<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * RFC 6238 time based one-time passwords.
 *
 * WebUntis hands out a base32 app secret in the user profile (shown as a QR
 * code). The mobile API expects a six digit token derived from it.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generate(
        string $secret,
        int $digits = 6,
        int $period = 30,
        ?int $timestamp = null,
    ): string {
        $key = self::decodeBase32($secret);
        $counter = pack('J', intdiv($timestamp ?? time(), $period));
        $hash = hash_hmac('sha1', $counter, $key, true);

        $offset = \ord($hash[19]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24)
            | (\ord($hash[$offset + 1]) << 16)
            | (\ord($hash[$offset + 2]) << 8)
            | \ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', \STR_PAD_LEFT);
    }

    private static function decodeBase32(string $input): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $input));
        if ('' === $clean) {
            throw new UntisException('The app secret is empty or not valid base32.');
        }

        $bits = '';
        foreach (str_split($clean) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if (false === $index) {
                throw new UntisException('The app secret contains characters outside base32.');
            }
            $bits .= str_pad(decbin($index), 5, '0', \STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === \strlen($chunk)) {
                $bytes .= \chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
