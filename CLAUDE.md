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

bin/shot /_design shot.png [width] [height]        # capture a page with headless Chrome (on the host)
```

`bin/shot` is a dependency-free Node script (host Node ≥ 22) that drives Chrome over the DevTools
protocol: it waits for the fonts, captures the viewport (height `0` = the whole document, for the
public pages), and signs in with `PEYRA_SHOT_LOGIN=<login>` (`/_dev/login-as/<login>`) or
`PEYRA_SHOT_COOKIE="name=value"`; `PEYRA_SHOT_RAIL=collapsed` sets the rail cookie. A keyboard
path is replayed and checked before the capture: `PEYRA_SHOT_FOCUS=<selector>` focuses an element,
`PEYRA_SHOT_KEYS="text:prof.leroy,Tab,Enter"` presses real keys, `PEYRA_SHOT_EVAL=<js>` prints what
an expression returns (awaited). The design gallery is <https://localhost:8143/_design> (with
`/_design/teacher` and `/_design/public`), development and test only: every Px component in every
state, in both shells.

The test database (`peyra_test`, and `peyra_test1`… for ParaTest through
`app:test:parallel-databases`) is described in `tests/README.md`, with the sequence CI replays.

Production needs both files, in this order (not deployed by the build agents):

```console
docker compose -f compose.yaml -f compose.prod.yaml build --pull
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

**Docker layer.** `compose.yaml` holds `php` (FrankenPHP + Caddy + the bundled Mercure hub) and
`gotenberg`; `compose.override.yaml` (dev) adds `database` (MariaDB 11.8 as in production, no published port),
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

## Design system (design-ui.md, built at step 0.3)

- **Stylesheet**: native CSS served by AssetMapper, no framework. `assets/styles/px.css` declares
  the layer order once (`px.reset, px.tokens, px.base, px.layout, px.components, px.utilities`) and
  `@import url(...)`s one file per family; `print.css` is linked apart (`media="print"`). The layer
  decides who wins: a layout rule that must hide a component (the top bar's menu button, the status
  bar's keys under 1024 px) sits in `px.utilities`, or the component's own `display` beats it.
- **Tokens only**: `assets/styles/tokens.css` is design-ui.md § 2.1 verbatim plus the values found
  missing (listed at its end). No colour in `components/` or `layout/` — `DesignTokensTest` refuses
  one, checks every `var(--px-…)` is declared, and recomputes the contrast pairs of § 2.3 from the
  file.
- **Components** `Px:*`: class in `src/Twig/Components/Px/` when there is a rule to hold (refused
  variant or tone, a count without its words, a trail starting with « Accueil »…), template in
  `templates/components/Px/`. Screens use the component, never the raw classes; the layout classes
  (`px-page`, `px-split`, `px-stack`, `px-cluster`, `px-kpis`) are the exception. A state's colour
  is on its enum (`App\Enum\Shared\HasBadge` + `BadgeTone`, `<twig:Px:Badge :of="…"/>`).
  `PxComponentsTest` pins their markup. Write `<tag{{ attributes }}>` with no space before:
  the attribute bag brings its own leading space.
  Inside a component's block, a prop of the component hides the page's variable of the same name
  (`rows` in a `Px:Table`, `label` or `error` in a `Px:Field`): name the page's variables apart.
- **Shells**: `layout/pgi.html.twig` (student), `layout/teacher.html.twig`, `layout/public.html.twig`.
  The first two capture their blocks (`{% set x %}{{ block('x') }}{% endset %}`) and include
  `layout/_shell.html.twig`: a child template's block placed inside a `<twig:…>` tag would belong
  to the component, not to the page. A screen sets `rail_home` and `status_keys` outside any block.
  The rail's folded state is the `px_rail` cookie, read by the server; error pages
  (`templates/bundles/TwigBundle/Exception/error.html.twig`) are in the public shell.
- **Keyboard**: one registry, `assets/shortcuts/registry.js` (canonical names such as `Ctrl+Enter`,
  `Alt+1`, `F10`), read by `shortcuts_controller.js` on `<body>`. An element answers a combination
  by declaring it, `data-px-shortcut="F10 Ctrl+S"` (`Px:Button`'s `shortcut` prop does it and
  prints the key); `[data-px-open="id"]` opens a dialog or drawer. `App\Twig\Shortcut` is the
  server's half of the French key labels (`Ctrl ↵`, `Échap`), tested against the JS table.
  Never name a Stimulus controller method `scope`, `data`, `element`… — they are the base class's.
- **Icons**: Lucide SVG files in `assets/icons/` (stroke 1.75), `px-*` ones drawn on the same grid;
  Iconify is off. `IconNamesTest` checks every name a template uses and the drawing rules.
- **Fonts** in `assets/fonts/` (`NOTICE`): Inter and Spectral are subsets made with fontTools; IBM
  Plex Mono is IBM's own split files, unmodified, because « Plex » is a reserved font name. Its Pi
  file carries U+202F, so amounts may use the narrow no-break space everywhere.
- **Brand** in `assets/brand/`: the P and the word PEYRA are vectorised (no Spectral Bold nor
  Manrope loaded); inline the wordmark with `{{ source('@brand/wordmark.svg') }}` so it takes the
  text colour.
- AssetMapper's import-map polyfill is off (`importmap_polyfill: false`): nothing is fetched from a
  CDN.

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
`User`, then bind again as the person's own DN. No password is ever stored. Peyra never writes to
the directory. Built at step 0.4; the pieces:

- **`App\Service\Identity\Directory`** is the directory, read-only: `LdapDirectory` everywhere
  but test, where `tests/Support/InMemoryDirectory` holds the same people (`config/services.yaml`,
  `when@test`); `LdapDirectoryTest` reads the real development one. A person's name is
  `givenName sn` (production's `cn` is the login), their groups the `cn` of every group naming
  their DN as `member` (`LDAP_GROUP_OBJECT_CLASS`).
- **The account is keyed on a stable id, never the login**: the attribute `PEYRA_LDAP_STABLE_ID`
  (`objectGUID` in production, `entryUUID` on the development OpenLDAP - an operational attribute,
  asked for by name), else `uidNumber`, stored `<attribute>:<value>` (`StableId`; a binary GUID is
  written in its text form). It is also `User::getUserIdentifier()`, so a session survives a
  renamed login. Re-seeding the development directory gives everybody new `entryUUID`s, hence new
  accounts: rename in place with `ldapmodrdn` instead when you test a rename.
- **The entry rule** is `App\Service\Identity\EntryRule::decide()`, a pure function (README of
  the plan, § 9): a student (group `student`) in a group of `PEYRA_STUDENT_GROUPS`; a teacher
  (group `teacher`) in a group of `PEYRA_TEACHER_GROUPS`, or a login of `PEYRA_ADMIN_LOGINS`, or
  authorised by name (`AuthorizedTeacher`, Administration › Enseignants de la filière); anybody
  else refused, Formatool's administrators included. `EntryPolicy` feeds it, and
  `App\Security\UserProvider` asks it again **at each request** on what the last sign-in read: a
  teacher removed from the list loses their session at the next click.
- **`App\Security\SignIn` is the one door of a sign-in** - the rate limiters (`login_ip`,
  `login_name`), the directory, the password, the rule, the account (`AccountProvisioner`), a line
  of the sign-in journal for every outcome - before any passport exists, so a refused person never
  gets an account. A wrong password and an unknown login read the same; the refusal is a sentence
  of design-ux.md § 4.1, never a reason. `LdapAuthenticator` only adds the form and its token.
- **Administrators**: `User::$administrator`, a teacher's flag. `PEYRA_ADMIN_LOGINS` sets it at
  each sign-in (and is never removed from the screen); an administrator designates others among
  the teachers; the last one never goes (`Administrators`). Every gesture of the administration
  screens goes to the administration journal; every sign-in to the sign-in journal. Both are read
  under Paramètres › Administration (`/teacher/admin/…`, `ROLE_ADMIN`).
- `/_dev/login-as/<login>` (dev and test only) signs in without a password, the rule still applied:
  `bin/shot` uses it (`PEYRA_SHOT_LOGIN`).

Real time is read where the request is (`ClockInterface` in `src/Security/` and the controllers)
and passed to the services as a date: `NoClockReadingTest` scans `src/Service/`.

Per-object rules belong in Voters, never inline in a controller. An object one may not see answers
a **404, never a 403**: `App\Security\NotFoundOnAccessDenied` (the firewall's
`access_denied_handler`) renders the « Page introuvable » page itself - the firewall refuses an
exception thrown from there. Never hand an authentication exception as the `previous` of a 404:
the firewall walks the chain and sends the person to the sign-in page instead.

### Demonstration accounts

The fictitious directory `frankenphp/ldap/10-tree.ldif` (password **`peyra-dev`** for all;
`PEYRA_ADMIN_LOGINS=prof.martin` in `.env.dev.local.example`):

| Login | Name | Groups | Enters as |
|---|---|---|---|
| `prof.martin` | Jean-Pierre Martin | teacher, cg-1, cg-2 | teacher, **administrator** (bootstrap list) |
| `prof.durand` | Sophie Durand | teacher, cg-1 | teacher |
| `prof.leroy` | Marc Leroy | teacher, sio-1 | **refused** until authorised by name |
| `lucas.bernard`, `manon.chevalier`, `theo.garnier`, `ines.morel`, `adam.roussel`, `chloe.fabre` | | student, cg-1 | students of first year |
| `yasmine.haddad`, `louis.perret`, `camille.dufour`, `nathan.mercier`, `lea.boyer`, `sacha.lambert` | | student, cg-2 | students of second year |
| `jules.martel` | Jules Martel | student, cg-1, cg-2 | student with two contradictory groups (« à placer », step 0.5) |
| `enzo.petit` | Enzo Petit | student, sio-1 | **refused** |

The service account is `cn=svc-peyra,ou=service,dc=peyra,dc=lan`. After changing the tree:
`docker compose rm -sf openldap && docker volume rm peyra_ldap_data && docker compose up -d openldap`.

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
