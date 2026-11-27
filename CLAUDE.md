# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

**Peyra** — a teaching ERP (« PGI pédagogique ») for the BTS Comptabilité et gestion (BTS CG).
The student side has the codes of a professional package (Sage, Cegid, Odoo, Pennylane): a dossier,
journals, keyboard entry, documents, bank reconciliation, declarations. The teacher side does what
none of them does: distribute a dossier to a class, generate the documents, follow the class live,
correct by comparison with an answer key, track the référentiel's activities, prefill the passeport
professionnel. The reference is the BTS CG référentiel, processes P1 to P7.

**Status: under construction**, lot by lot, by autonomous agents following
`design/validated/plan/` (start with its `README.md`, then `ETAT.md` for the step in hand). Deployed
for Institution Beaupeyrat, which will use it — the application is not theirs (see Licensing).

**The conception lives in `design/validated/`**, which is gitignored and never committed:

- `pgi-pedagogique.md` — the design, in French. Its dated decisions are the user's and are binding;
  its numbered sections (§ 3 bis, § 5…) are what this file refers to. Read it before building
  anything.
- `pgi-pedagogique-screens.html` — the page written for the teachers who are the project's référents
  (mock-ups, comparison with the market tools, lots, decisions). It is a build output: edit
  `source/` and run `python3 -I design/validated/source/build.py`, never the HTML itself.
- `design-ux.md` and `design-ui.md` — the UX and UI specifications arbitrated from the mock-ups.
- `plan/` — the build plan: how to work, one file per lot, progress, decisions, points to verify.
- `logos/` — the logo board (the seal was chosen); `references/` — the documents the conception
  cites.

**Sister project: Formatool**, the institution's campus platform (`/Users/Shared/Projets/Symfony/moncampus`,
GitHub `DrakanAethril/moncampus`). Peyra is deliberately a separate application (conception § 2):
same stack, same hosting, same LDAP directory, nothing else shared. When a rule below says « as in
Formatool », Formatool's `CLAUDE.md` holds the long version and the reason. Say « Formatool », never
« MonCampus ».

## Stack (planned, conception § 8)

Symfony 8.1, PHP ≥ 8.5, Doctrine ORM 3, **MariaDB in dev as in production** — migrations are
portable SQL (no `CAST(… AS JSON)`, nothing MySQL-only). FrankenPHP in worker mode, scaffolded
from `dunglas/symfony-docker` as Formatool was. Stimulus, Turbo and AssetMapper; Gotenberg for PDFs
(generated documents, statements, payslips, tax return); Mercure for the live follow-up; Symfony
Scheduler and a `worker` service for background jobs; S3 for uploaded documents (flysystem, never
the local filesystem); sql.js in the browser for the SQL query tool (P7).

**Not reused from Formatool**: Tabler, `app.css` and its `cm-*` design system. The student side has
its own stylesheet — dense grids, everything reachable from the keyboard, tabular figures, desktop
first (1280 px and up). The teacher side may borrow the spirit of Formatool's look. Colours are the
institution's: blue `#1B6BA8` (primary action, current line, steps), night blue `#12344d` (rail),
gold `#c9a04e` (active rail entry, as in the logo); green is reserved for validated states. No
colour or layout copied from a vendor: the genre, not the copy.

## Commands

Everything runs inside the `php` container; there is no host-side PHP.

```console
cp .env.dev.local.example .env.dev.local      # once: local, fictitious credentials
docker compose build --pull                   # build peyra-php-dev
docker compose up --wait                      # start (dev overlay is implicit)
docker compose down --remove-orphans          # stop — only ever from this directory

docker compose exec php bin/console <cmd>
docker compose exec php composer <cmd>
docker compose exec -e APP_ENV=test php bin/phpunit
docker compose exec -e APP_ENV=test php vendor/bin/paratest --processes=4   # setup: tests/README.md
docker compose --profile worker up -d worker  # the scheduler's worker, opt-in in dev

docker compose exec php composer cs-check          # coding standard (cs-fix applies it)
docker compose exec php bin/console cache:warmup   # PHPStan reads the compiled dev container
docker compose exec php composer phpstan           # static analysis, level 5 + checkExplicitMixed
```

The test database (`peyra_test`, and `peyra_test1`… for ParaTest through
`app:test:parallel-databases`) is described in `tests/README.md`, with the sequence CI replays.

Production needs both files, in this order (not deployed by the build agents):

```console
docker compose -f compose.yaml -f compose.prod.yaml build --pull
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

**Docker layer.** `compose.yaml` holds `php` (FrankenPHP + Caddy + the bundled Mercure hub) and
`gotenberg`; `compose.override.yaml` (dev) adds `database` (MariaDB 11.4, no published port),
`openldap` (the fictitious directory, `frankenphp/ldap/*.ldif`, loaded on an empty volume only) and
`worker` behind a profile; `compose.prod.yaml` adds the production target and `worker`. The
Compose project is `peyra`, images `peyra-php-dev` / `peyra-php-prod`, ports 8180 / 8143 (`.env`):
Formatool runs on the same machine on 80/443 with `app-php-dev`, and nothing here may touch it.
Dev address: <https://localhost:8143>, liveness probe `/health` (no database).

Gotchas met while building it:

- `SERVER_NAME` declares two sites (`localhost` and `php:80`, the address the application publishes
  to), so the Caddyfile's Mercure hub **must be named** (`name peyra`): the hub bundled since
  FrankenPHP 1.13 refuses two unnamed instances and the container never becomes healthy.
- `composer.json` sets `extra.symfony.docker: false`: Flex recipes must not rewrite the Compose
  files (the Doctrine recipe would add a PostgreSQL service). PHP extensions are installed once,
  for the whole project, in the `Dockerfile`.
- The scheduler's recipe creates `src/Schedule.php`; Peyra's schedule is
  `App\Scheduler\PeyraSchedule` (`default`), and two providers for one name break the container.
- `frankenphp/mariadb/10-test-databases.sh` (grants the application user its `peyra_test…`
  databases, on an empty volume only) is **executable** on purpose: Docker Desktop reports a
  bind-mounted file as executable whatever its mode, so the MariaDB image runs it instead of
  sourcing it, and a script written to be sourced dies with « bad interpreter ».
- Until the first migration exists, `doctrine:migrations:migrate` fails without
  `--allow-no-migration`; CI passes it, and it is a no-op afterwards.

## Domain rules the code must hold

These come from the conception. Hold each one in code — a filter, a constraint, a single door —
never by discipline.

- **Every business row carries `dossier_id`**, and no screen or query reads without it: a global
  Doctrine filter (§ 4).
- **A dossier belongs to the student, not to the class** (§ 3 bis). Classes exist per school year; a
  student has one enrolment per year, and past ones are history. The directory group (`cg-1` → CG1,
  `cg-2` → CG2) places a student **once, when the account is created**; afterwards placement is
  manual only, plus the start-of-year « passage de classe » gesture. The account is keyed on a
  stable directory id (`objectGUID`, else `uidNumber`), never the login. **Nothing is ever deleted
  automatically.** A teacher who no longer has a student keeps reading the missions they gave them.
- **Amounts are integer cents**, never floats; each fiscal rounding is written once, by rule (§ 5).
- **An entry is balanced or does not exist.** A draft is editable; a validated entry never changes
  and is never deleted — it is reversed. Numbering is continuous at validation. A closed period
  refuses entries.
- **Balances are a reading** (the sum of the lines), never a stored counter.
- **Sales, purchases, payroll and fixed assets never write entries themselves**: they ask the
  accounting engine, through one door, which applies the mission's assistance level.
- **No business calculation reads the server clock.** Each dossier has its own date, moved by the
  teacher; real timestamps serve the audit trail only.
- **Regulatory parameters are dated values** (millésimes), read at the dossier's date.
- **Nothing leaves**: tax, social and e-invoicing portals are simulated inside the application.
  No call to a real service.
- **The correction is a pure function over primitives**, tested as such (§ 6). The answer key never
  reaches a student's browser, and the teacher's decision is the only assessment kept.
- **The SQL tool never touches the application database** (§ 7): a documented teaching schema,
  exported to SQLite and queried read-only in the browser.
- **Personal data** are the students' names and the trace of their work, read by their teachers.
  The companies and employees are fictitious.

## Authentication and access

LDAP as in Formatool: bind as the service account, search `(uid=…)`, find-or-create the local
`User`, then bind again as the person's own DN. No password is ever stored. The entry rule is
`(ROLE_TEACHER or ROLE_STUDENT) AND a CG group`; every other account is refused at login,
Formatool's administrators included. There is no administrator role in the directory for Peyra:
administration belongs to teachers designated inside the application. Peyra never writes to the
directory.

Per-object rules belong in Voters, never inline in a controller. An object one may not see answers
a **404, never a 403**.

## Conventions (as in Formatool)

- **Code is English, display text is French.** Identifiers, comments and docblocks are English; only
  user-visible strings are French. The domain vocabulary the code speaks may stay French where an
  English word would mislead (dossier, écriture, brouillard, lettrage, liasse…) — fix the list early
  and keep to it. Screen names quoted inside an English comment stay French.
- **URLs and route names are English.**
- **No translation layer**: French text is written directly in templates and in enums' `label()`.
- **Layout**: Symfony's by-type folders with one sub-namespace per domain
  (`src/Entity/Accounting/`, `src/Service/Accounting/`, `templates/accounting/`…).
- **Business rules belong in `src/Service/`**, not in controllers. When an area grows, add a
  controller rather than grow one.
- **Type at the boundary, never cast further in.** Recreate Formatool's readers rather than casting
  inline: `JsonRequestPayload` (fetch bodies), `QueryValue` (query strings — `InputBag::getInt()`
  answers a 400 to the empty string a filter bar submits), `FormValue` (`getData()` is mixed),
  `DataTableParams` (paging, capped). Shapes are `@phpstan-type` on the producer,
  `@phpstan-import-type` where consumed.
- **Interactivity is Stimulus controllers** under `assets/controllers/`; never inline JS. A POST form
  handled by Turbo must redirect; a « show me a result » form uses `GET`.
- **Forms**: checkbox groups rather than `<select multiple>`; a select used for input has a
  placeholder; picking users uses an autocomplete fed by ajax.
- **Worker mode**: a service that keeps state between calls implements `ResetInterface`. A scheduled
  command that locks itself uses a shared-lock trait like Formatool's `SharedLockableTrait`, never
  Symfony's `LockableTrait` (the worker reruns the same instance).
- **Publishing to Mercure is a comfort, never a condition**: catch and log, since CI's publisher key
  makes every publish throw.
- **Indentation** follows `.editorconfig`: 4 spaces; tabs in Dockerfile, Caddyfile and `*.sh`;
  2 spaces in Compose and GitHub Actions YAML.
- **Configuration** goes through `.env`, not by editing `compose.yaml` or the Caddyfile.

## Quality

- **PHPStan level 5 plus `checkExplicitMixed`**, over `src/` and `tests/`, with the Doctrine, Symfony
  and PHPUnit extensions, and an **empty baseline from the first commit**: any error is yours. A
  standing exemption goes in `phpstan.dist.neon`'s `ignoreErrors`, next to its reason.
- **PHP CS Fixer, `@Symfony`**, no risky rules, `migrations/` and `design/` excluded,
  `phpdoc_align` off.
- **No Rector in the toolchain.** Never run `ControllerMethodInjectionToConstructorRector` nor
  `RemoveDefaultValueFromAssignedPropertyRector` (see Formatool's `CLAUDE.md` for why).
- **Tests**: unit tests over pure services, one test per Voter, a functional smoke test that requests
  each main screen as a student and as a teacher. The accounting engine's invariants, the
  correction and payroll are tested on reference cases of each millésime. ParaTest for the suite.
- **CI** (GitHub Actions, one job, on every push to `staging` and every pull request): `cs-check`,
  `lint:yaml`, `lint:twig`, `lint:container`, PHPStan, migrations replayed from an empty database
  then `doctrine:schema:validate`, the suite. No repository secret. A push touching only `**.md`
  may skip it — on the `push` trigger only, never on `pull_request`. Concurrency grouped by
  `github.head_ref || github.ref`.

## Git workflow

- `main` is production, `staging` takes direct pushes. Once CI exists, protect them as Formatool's
  rulesets do: `main` through a pull request with the `Checks` status required, signed commits, no
  deletion, no force-push on either.
- **Branch before the first edit of any task** (`git checkout -b <name> origin/staging`), then land
  the work on `staging` in the same turn it is verified: commit, `git merge --no-ff` into `staging`,
  `git push origin staging`, `git branch -d` the feature branch. Feature branches are local-only.
  Work directly in this checkout, never in a git worktree.
- **Commit messages are French**, `type(scope): …`, with **no `Co-Authored-By` trailer**. Commits are
  SSH-signed.
- **A production deploy is the only action always confirmed before running**: a pull request from
  `staging` to `main`, merged once green. When deployment exists, each release writes its note as
  Formatool's `config/changelog.yaml` does (CalVer, the date it went live).

## Licensing

Peyra is **AGPL-3.0-or-later**, copyright Sébastien Tharaud (`LICENSE`, `NOTICE`).

- Any new dependency must be AGPL-compatible: MIT, BSD, Apache-2.0 and ISC are fine; proprietary or
  incompatible components are not. A bundled third-party file (sql.js, a font, an icon set) is
  listed in `NOTICE` with its licence when it is added.
- AGPL §13: users interacting with Peyra over a network must be able to obtain its source. Keep a
  visible source-code link working, pointing at <https://github.com/DrakanAethril/Peyra>.
- Institution Beaupeyrat's names, logos and emblems are excluded from the licence; any file carrying
  them is listed in `NOTICE`. Peyra's own logo (the seal) does not reuse the institution's emblem.
