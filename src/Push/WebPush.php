<?php

declare(strict_types=1);

namespace App\Push;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends Web Push messages straight from this server, with nothing but the
 * openssl extension: VAPID (RFC 8292) to identify the server to the push
 * service, aes128gcm payload encryption (RFC 8291/8188) so the push service
 * - Apple's or Google's, whichever the browser subscribed with - only ever
 * relays ciphertext it cannot read.
 *
 * The VAPID key pair is generated on first use and kept in var/ (never in
 * untis.yaml, never in the image). Losing it invalidates every existing
 * subscription: the browsers have to subscribe again.
 */
final class WebPush
{
    /** DER prefix of a P-256 SubjectPublicKeyInfo, followed by the 65-byte uncompressed point. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Fallback VAPID `sub` claim for a subscription not made from an https page. */
    private const FALLBACK_SUBJECT = 'mailto:webuntis-dashboard@example.com';

    private ?\OpenSSLAsymmetricKey $privateKey = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $keyFile,
    ) {
    }

    /** The VAPID public key, base64url, as the browser's `applicationServerKey`. */
    public function publicKey(): string
    {
        return self::base64Url(self::rawPublicKey($this->privateKey()));
    }

    /**
     * Encrypts and sends $payload (JSON-encoded) to one subscription. Returns
     * the push service's HTTP status - 201 means accepted, 404/410 mean the
     * subscription is gone for good - or 0 when it could not be reached.
     *
     * @param array<string, mixed> $payload
     */
    public function send(Subscription $subscription, array $payload): int
    {
        $body = $this->encrypt(
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            self::base64UrlDecode($subscription->p256dh),
            self::base64UrlDecode($subscription->auth),
        );

        $url = parse_url($subscription->endpoint);
        $audience = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        $subject = str_starts_with($subscription->origin, 'https://') ? $subscription->origin : self::FALLBACK_SUBJECT;

        try {
            $response = $this->httpClient->request('POST', $subscription->endpoint, [
                'headers' => [
                    'Authorization' => \sprintf('vapid t=%s, k=%s', $this->jwt($audience, $subject), $this->publicKey()),
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type' => 'application/octet-stream',
                    'TTL' => '86400',
                    'Urgency' => 'high',
                ],
                'body' => $body,
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 300) {
                $this->logger->warning('Push service answered {status} for {host}: {body}', [
                    'status' => $status,
                    'host' => $url['host'],
                    'body' => substr($response->getContent(false), 0, 300),
                ]);
            }

            return $status;
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Push service {host} unreachable: {message}', [
                'host' => $url['host'],
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * RFC 8291: an ephemeral ECDH key pair per message, combined with the
     * browser's own key and auth secret into a one-off content key, then a
     * single aes128gcm record (RFC 8188) carrying the ephemeral public key
     * in its header so the browser can derive the same key.
     */
    private function encrypt(string $plaintext, string $uaPublic, string $authSecret): string
    {
        $local = self::newKey();
        $asPublic = self::rawPublicKey($local);

        $shared = openssl_pkey_derive(self::publicKeyFromRaw($uaPublic), $local);
        if (false === $shared) {
            throw new \RuntimeException('ECDH key agreement failed.');
        }

        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // 0x02 marks the last (and only) record.
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, \OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (false === $ciphertext) {
            throw new \RuntimeException('Payload encryption failed.');
        }

        return $salt.pack('N', 4096).\chr(\strlen($asPublic)).$asPublic.$ciphertext.$tag;
    }

    /** The VAPID JWT (ES256), valid for 12 hours. */
    private function jwt(string $audience, string $subject): string
    {
        $header = self::base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], \JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $subject,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        if (!openssl_sign($header.'.'.$claims, $der, $this->privateKey(), \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing the VAPID token failed.');
        }

        return $header.'.'.$claims.'.'.self::base64Url(self::derSignatureToRaw($der));
    }

    /**
     * Loads the VAPID private key from var/, creating it on first use. The
     * file is created exclusively ('x'), so two requests racing to create it
     * cannot end up handing out two different public keys.
     */
    private function privateKey(): \OpenSSLAsymmetricKey
    {
        if (null !== $this->privateKey) {
            return $this->privateKey;
        }

        if (!is_file($this->keyFile)) {
            $dir = \dirname($this->keyFile);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Could not create %s.', $dir));
            }

            if (!openssl_pkey_export(self::newKey(), $pem)) {
                throw new \RuntimeException('Could not export the VAPID key.');
            }

            $handle = @fopen($this->keyFile, 'x');
            if (false !== $handle) {
                chmod($this->keyFile, 0600);
                fwrite($handle, $pem);
                fclose($handle);
            }
        }

        $key = openssl_pkey_get_private((string) file_get_contents($this->keyFile));
        if (false === $key) {
            throw new \RuntimeException(\sprintf('%s is not a valid private key.', $this->keyFile));
        }

        return $this->privateKey = $key;
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        if (false === $key) {
            throw new \RuntimeException('Could not generate a P-256 key.');
        }

        return $key;
    }

    /** Uncompressed point (0x04 || x || y), the form Web Push exchanges keys in. */
    private static function rawPublicKey(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (false === $details || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('Not an EC key.');
        }

        return "\x04"
            .str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT);
    }

    private static function publicKeyFromRaw(string $raw): \OpenSSLAsymmetricKey
    {
        if (65 !== \strlen($raw) || "\x04" !== $raw[0]) {
            throw new \InvalidArgumentException('Not an uncompressed P-256 public key.');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX).$raw), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);
        if (false === $key) {
            throw new \InvalidArgumentException('Invalid P-256 public key.');
        }

        return $key;
    }

    /** openssl_sign() returns a DER ECDSA signature; JWS wants raw r || s. */
    private static function derSignatureToRaw(string $der): string
    {
        $offset = 2;
        $raw = '';
        for ($i = 0; $i < 2; ++$i) {
            $length = \ord($der[$offset + 1]);
            $integer = ltrim(substr($der, $offset + 2, $length), "\0");
            $raw .= str_pad($integer, 32, "\0", \STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Not valid base64url.');
        }

        return $decoded;
    }
}
