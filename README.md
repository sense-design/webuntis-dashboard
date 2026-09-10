# WebUntis Dashboard

A single page showing today's WebUntis timetable for several students side by
side. Built for a phone at breakfast or a small screen in the hallway: what is
on today, and what changed.

Symfony 7, PHP-FPM, nginx. No database, no queue, no build step. The only state
is a filesystem cache under `var/`.

## How it works

WebUntis exposes the JSON-RPC endpoints that the official mobile app uses.
Schools enable different login paths, so both are supported:

| Method | Endpoint | Credentials |
| --- | --- | --- |
| `secret` | `POST /WebUntis/jsonrpc_intern.do?m=getUserData2017` | username + TOTP from the app secret |
| `password` | `POST /WebUntis/jsonrpc.do` (`authenticate`) | username + password |

Prefer `secret`. It is the path the Untis app itself takes and it keeps working
at schools that have switched the classic endpoint off. The secret is a base32
string from the WebUntis profile, under access sharing, shown as a QR code. TOTP
generation is implemented in `src/Untis/Totp.php` against RFC 6238, so no OTP
library is needed.

Both paths end up with a `JSESSIONID`, which the timetable request reuses.

## Setup

```bash
composer install --no-dev --optimize-autoloader
cp config/untis.yaml.dist config/untis.yaml
```

Edit `config/untis.yaml`: the server host, the school login name from the
WebUntis URL, one account block per login, one student block per child.

Then set a real `APP_SECRET` in `.env.local` and make `var/` writable by the
PHP-FPM user:

```bash
echo "APP_SECRET=$(openssl rand -hex 16)" > .env.local
mkdir -p var && chown -R www-data:www-data var
```

### Finding the element ids

A parent account is not itself a student, so each child needs an `element_id`.
Set `setup_token` in `config/untis.yaml` and visit
`/setup/<account-id>?token=<setup_token>` once — for the example config that is
`/setup/parent?token=...`. It returns the account's own element plus every
linked child:

```json
{
  "own_element": {"element_id": 42, "element_type": 5, "display_name": "Erika Musterfrau"},
  "children": [
    {"element_id": 1234, "display_name": "Mila"},
    {"element_id": 5678, "display_name": "Jonas"}
  ]
}
```

Copy the ids into the config. If `children` is empty and the account *is* the
student, leave `element_id` out entirely and the account's own element is used.
The `raw_user_data` field is there for the cases where a school's WebUntis
version names things differently.

The `/setup` route echoes account details, so it stays locked behind
`setup_token`: any request without a matching `?token=` gets a plain 404. Clear
the token from the config to disable the route entirely.

## Deployment

`nginx.conf.example` is a complete vhost. Document root is `public/`, everything
routes through `index.php`, and PHP-FPM is the only moving part.

The dashboard shows where two children are at any hour of the day. Put HTTP basic
auth, an IP allowlist, or your existing SSO in front of it, and serve it over
TLS only. `config/untis.yaml` holds credentials in cleartext, so it is gitignored
and should be `chmod 600` and owned by the PHP-FPM user.

## Options

| Key | Default | Effect |
| --- | --- | --- |
| `cache_ttl` | `300` | Seconds a fetched day is reused before WebUntis is asked again |
| `refresh_seconds` | `600` | Browser auto-reload interval; `0` disables it |
| `timezone` | `Europe/Berlin` | Decides which day "today" is |
| `locale` | `en` | UI language, `en` or `de` |

`?day=tomorrow` or `?day=2026-09-14` shows another day. The header menu has a
day picker covering the previous, current and coming week.

The UI ships in English and German, set app-wide by `locale`. Strings live in
`translations/en.yaml` and `translations/de.yaml`.

## Behaviour worth knowing

- Adjacent periods of the same lesson are merged, so a double period is one
  block from 08:00 to 09:30 rather than two rows. Periods more than five
  minutes apart stay separate, so real breaks survive.
- Cancelled lessons stay visible, struck through and marked. Removing them
  would hide the thing you opened the page for.
- Substitutions show the teacher who was replaced when WebUntis reports it.
- One student failing does not break the page. The error appears in that
  student's column and the other columns still render.

## Possible next steps

- Free period markers between blocks, so "starts at 09:50" reads at a glance
- Homework and exams, both reachable through the same session
- An iCal feed per student for the family calendar
- Push on change: diff the cached day against a fresh fetch and send on delta

## Layout

```
src/Untis/UntisClient.php       JSON-RPC calls, both login paths, normalisation
src/Untis/Totp.php              RFC 6238 tokens from the app secret
src/Untis/ConfigLoader.php      Reads config/untis.yaml
src/Untis/TimetableProvider.php Session reuse per account, caching, error isolation
src/Controller/DashboardController.php  Dashboard page and the /setup helper
src/I18n/Translator.php         Two-language message catalogue, no framework i18n
src/Twig/I18nExtension.php      The t() Twig function
translations/{en,de}.yaml      UI strings, English and German
templates/dashboard.html.twig   The page
public/assets/style.css         The styles
```

## Author

Sven Culley — <sven@sense-design.de> ([sense-design.de](https://sense-design.de))

MIT licensed.
