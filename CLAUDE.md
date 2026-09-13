# WebUntis Dashboard

Standalone Symfony 7 app that renders one day of WebUntis timetable data for
several students. Runs on nginx + PHP-FPM only; no database, no build step
for the app itself (bare metal, per `nginx.conf.example`, or the one-image
`Dockerfile` - same nginx + PHP-FPM, just both in one container).

## Rules

- All code, comments, documentation and commit messages in English.
  User-facing strings live in `translations/<locale>.yaml`, keyed and reached
  through `App\I18n\Translator` (the `t()` Twig function). `templates/` and
  `DashboardController` hold no literal UI text in either language.
- Keep the dependency list short. Anything solvable with the standard library
  or a Symfony component already pulled in should not add a package.
- `config/untis.yaml` holds credentials and is never committed.
- CSS is plain CSS in `public/assets/style.css`. No build pipeline, no
  framework, no utility classes.
- Every colour is one of the custom properties on `:root` (`--paper`,
  `--card`, `--ink`, `--muted`, `--rule`, `--alert`, `--shift`, `--accent`) —
  a literal hex colour anywhere outside the three theme blocks in
  `style.css` either can't be reached from a token or will not adapt to
  dark mode. `color: #fff` is the one intentional exception, always paired
  with `background: var(--accent)`, since that combination is designed to
  work in both themes. Theme defaults to following the system setting; the
  `theme` setting (`system`/`light`/`dark`, `/admin` or `theme` in
  `untis.yaml`) can pin it instead via `html[data-theme]`
  (`ConfigLoader::theme()`, set on `<html>` in all three templates — omitted
  entirely for `system`, so the `@media (prefers-color-scheme: dark)` block
  is what applies then). The dark tokens are declared twice in `style.css`
  on purpose: once gated by that media query for the system-follows case,
  once under `:root[data-theme="dark"]` for the pinned case — keep both in
  sync when a token's dark value changes.
- Optional features (homework, exams, free-period markers) are on by default
  and switched off individually via `features.<name>` in `untis.yaml`, or
  from `/admin`, read through `ConfigLoader::<name>Enabled()`. A disabled
  view route 404s (see `DashboardController::homework()`/`exams()`) rather
  than rendering empty; a disabled display-only feature (free periods) just
  renders without it. A new optional feature should follow the same shape.
- `config/untis.yaml` is never written to by the app, only read. Anything a
  user should be able to change at runtime (`/admin`) is layered on top of it
  from `var/settings.yaml` instead, via `ConfigLoader::saveSettings()` /
  the private `settings()` overlay. Do not add a form field for anything
  that lives only in `untis.yaml` (accounts, students, server/school,
  timezone, either token) — those stay a manual edit on purpose. The one
  per-student exception is `hide_subjects`: not a credential, and exposed
  through its own page, `/admin/subjects` (same `admin_token` gate, reached
  from `/admin` via the same `.subviews` tab-pair component the
  homework/done split uses, kept apart from the general settings form
  because it needs a live WebUntis fetch to build its checklist rather than
  just reading `untis.yaml`), keyed by student *name* (`ConfigLoader::hiddenSubjects()` /
  `allHiddenSubjects()`) rather than by array index, so it survives
  untis.yaml being reordered or gaining a new student. Both `/admin` and
  `/admin/subjects` save through the same `ConfigLoader::saveSettings()`,
  which replaces the whole settings file — so each route's save must carry
  forward the *other* route's slice unchanged (`readSubmittedSettings()`
  reuses `allHiddenSubjects()` as-is; `subjects()` starts from
  `currentSettings()`), or one page's save would silently erase the other's
  values. A subjects save always resubmits every currently-known student, so
  a student whose live subject fetch fails on the way in must be left out of
  that resubmission (via the `hide_subjects_shown` marker in
  `admin_subjects.html.twig`) rather than saved as empty — otherwise a
  WebUntis hiccup during an unrelated field change would silently clear
  their hidden subjects. `AdminController::subjects()` also clears the whole
  cache pool after every save (`admin()` does not need to): unlike the other
  settings, `hide_subjects` is baked into `TimetableProvider::fetchDay()`'s
  cached result at fetch time rather than read fresh at render time, so
  without the clear a change would sit invisible for up to `cache_ttl`
  seconds on every day already cached.
- Marking homework done (`/homework/{id}/done` and `/homework/{id}/open`) is
  purely local, the same "layer state in `var/`, never touch WebUntis or
  `untis.yaml`" pattern as `/admin` settings — see `HomeworkTracker`. It is
  intentionally not token-gated like `/admin`: anyone who can load the
  dashboard (already restricted at the network level, per the README) can
  tick a box, since this is meant for everyday use by the whole family, not
  a maintenance action.
- `config/untis.yaml` and `var/` are never baked into the Docker image
  (`.dockerignore` + a belt-and-suspenders `rm` in the build stage for the
  former) - both are mounted at container start instead, same "credentials
  and runtime state stay outside the app" rule as bare metal, just via
  volumes rather than gitignored host paths. `docker/entrypoint.sh` checks
  for the mounted `config/untis.yaml` and exits with a clear message if it
  is missing, rather than letting every request 500. Don't add anything
  that writes inside the image itself at runtime - it won't be there after
  a restart unless it is under `var/`, the one path that is a volume.

## Gotchas

- `UntisClient` takes scalar constructor arguments and must stay excluded from
  autowiring in `config/services.yaml`. It is built by `TimetableProvider`.
- WebUntis element types: 1 class, 2 teacher, 3 subject, 4 room, 5 student.
- `elemType` comes back as an int at some schools and as a string at others;
  `normaliseElementType()` handles both. Do not simplify it away.
- The parent-account child list is `children` at some schools and `students`
  at others. Both are read.
- The OTP login returns the session in a `Set-Cookie` header, not in the JSON
  body. The password login returns it in the body.
- Times arrive as integers (`800` means 08:00), dates as `Ymd` integers.
- i18n is the hand-rolled `App\I18n\Translator`, not `symfony/translation`.
  Two YAML catalogues only (`en`, `de`); keep their keys in sync. Keys are dot
  paths into the tree. The language is app-wide from `locale` in `untis.yaml`
  (`en` if unset or unknown); there is no per-request switch.
- Static files are always reached through `{{ asset(...) }}`, never a literal
  `/assets/...` path, so `App\Asset\MtimeVersionStrategy` (wired in
  `config/packages/framework.yaml`) can append each file's own mtime as a
  `?v=` cache-buster. That is what lets `nginx.conf.example` cache
  `/assets/` hard without a deploy leaving the browser stuck on a stale
  `style.css`.
- The `<link rel="manifest">` tag carries `crossorigin="use-credentials"`. Web
  app manifest fetches omit credentials by default even for same-origin
  requests (unlike a normal `<link>`/`<img>`), so behind the HTTP basic auth
  the README recommends, the browser would otherwise get a 401 for
  `/manifest.webmanifest` and never load the home-screen icons. Do not drop
  this attribute as dead weight.
- `/WebUntis/api/exams` filters by `klasseId`, not by student, so
  `getExams()` fetches with `klasseId=-1` and matches each exam's own
  `assignedStudents` list, mirroring how `getHomework()` matches `records`.
  Some schools return `403 Unerlaubter Zugriff` here for every account
  regardless of parameters — the exams module is a separate right the school
  has to grant, and a parent/student account may simply not have it. That
  surfaces as the normal per-student error, same as any other WebUntis
  failure; it does not mean the request is malformed.
