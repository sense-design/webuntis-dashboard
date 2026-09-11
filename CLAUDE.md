# WebUntis Dashboard

Standalone Symfony 7 app that renders one day of WebUntis timetable data for
several students. Runs on nginx + PHP-FPM only; no database, no build step.

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
- Optional features (homework, exams, free-period markers) are on by default
  and switched off individually via `features.<name>` in `untis.yaml`, read
  through `ConfigLoader::<name>Enabled()`. A disabled view route 404s (see
  `DashboardController::homework()`/`exams()`) rather than rendering empty; a
  disabled display-only feature (free periods) just renders without it. A new
  optional feature should follow the same shape.

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
