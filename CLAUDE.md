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

Everything runs inside the `php` container; there is no host-side PHP. The stack, from this
directory only (Formatool runs on the same machine, under its own project name):

```console
cp .env.dev.local.example .env.dev.local      # once: local, fictitious credentials
docker compose build --pull                   # build peyra-php-dev
docker compose up --wait                      # start (dev overlay is implicit)
docker compose restart php                    # after adding a directory under src/ or a route (worker mode)
docker compose --profile worker up -d worker  # the scheduler's worker, opt-in in dev
docker compose down --remove-orphans          # stop — only ever from this directory
docker compose exec php bash                  # a shell
```

A development database from nothing, then the dev data (students are placed by their own first
sign-in, as in production):

```console
docker compose exec php bin/console doctrine:migrations:migrate -n
docker compose exec php bin/console app:dev:school           # dev and test only: years, classes, mapping, teachers
docker compose exec php bin/console app:regulatory:sync      # installs config/regulatory/<année>.yaml
docker compose exec php bin/console app:demo:install         # Menuiserie Vasseur, a template of the common library
docker compose exec php bin/console app:dev:dossiers         # dev and test only, after the students' first sign-in: their own copy
```

Quality — the definition of done of a step runs all of it (plan README § 6):

```console
docker compose exec php composer cs-check          # coding standard (cs-fix applies it)
docker compose exec php bin/console cache:warmup   # PHPStan reads the compiled dev container
docker compose exec php composer phpstan           # static analysis, level 5 + checkExplicitMixed
docker compose exec php bin/console lint:yaml config && docker compose exec php bin/console lint:twig templates && docker compose exec php bin/console lint:container
docker compose exec -e APP_ENV=test php bin/phpunit                  # the suite, in one process
docker compose exec -e APP_ENV=test php bin/phpunit --filter <Test>
docker compose exec -e APP_ENV=test php vendor/bin/paratest --processes=4   # in parallel; setup: tests/README.md
```

Run the suite both ways before pushing, as CI does: ParaTest gives every test file its own
process, so a test that leaks global state (an environment variable unset, a static) only breaks
the files after it under `bin/phpunit`.

Application commands (`src/Command/`). **Three are scheduled** (`app:restore-points:advance`,
`app:distribution:advance` and `app:fec-import:advance`, every minute): `App\Scheduler\PeyraSchedule` holds the list, and
`PeyraScheduleTest` pins it, so adding a task is a decision the test makes visible.

| Command | Role |
|---|---|
| `app:regulatory:sync [--dry-run]` | Installs the millésime files `config/regulatory/<année>.yaml`; only ever adds rows, never over an administrator's value. **Every deploy runs it** |
| `app:ldap:check [login]` | **Diagnostic.** Says whether the directory answers and the service account binds, and how a person is read (DN, groups, the entry rule's decision), without printing a secret |
| `app:dev:school` | **Development and test only.** 2025-2026 and 2026-2027 (current), BTS CG 1 and 2, the mapping `cg-1`/`cg-2`, the teachers of the classes |
| `app:demo:install` | Installs the demonstration dossier, Menuiserie Vasseur SARL (`fixtures/demo/`: company and exercises, the PCG 2026 plus its own accounts, six journals, 59 third parties, the opening balance of 2026 as one validated entry of AN, the first quarter in every journal with drafts at the end of March, seven entry templates, two subscriptions, three suppliers lettered, the payroll settings and eleven employees; date 31/03/2026), as a template of the **common library** (no author). Development and production: run once at the first deploy (`docs/production.md`), never by `deploy-prod.sh` - an installed template is never replaced, and one an administrator deleted must not come back |
| `app:dev:dossiers` | **Development and test only.** Distributes Menuiserie Vasseur (the common library's template, installed first if missing) to each class of the current year - every student who holds no copy of it yet -, then as one shared dossier to `ines.morel` (Comptable fournisseurs), `theo.garnier` (Comptable clients) and `chloe.fabre` (Chef comptable) when enrolled in one class; the copies are made at once. Sign the students in first: their first sign-in places them |
| `app:distribution:advance` | **Scheduled every minute.** Makes the dossiers of the distributions a request left waiting (a worker restarted), one copy per pass under its own lock; usually finds nothing |
| `app:fec-import:advance` | **Scheduled every minute.** Runs the passes of the FEC imports a request left (the page's 12 s budget, a worker restarted); a pass that fails stops its import, never retried on its own |
| `app:restore-points:advance` | **Scheduled every minute.** Finishes the restore points still in preparation and the returns still running that a request left (a worker restarted); usually finds nothing. Takes each dossier's lock |
| `app:test:parallel-databases` | **Test only.** Copies the migrated `peyra_test` into `peyra_test1`… for ParaTest (tests/README.md) |

```console
bin/shot /_design shot.png [width] [height]        # capture a page with headless Chrome (on the host)
```

`bin/shot` is a dependency-free Node script (host Node ≥ 22) that drives Chrome over the DevTools
protocol: it waits for the fonts, captures the viewport (height `0` = the whole document, for the
public pages), and signs in with `PEYRA_SHOT_LOGIN=<login>` (`/_dev/login-as/<login>`) or
`PEYRA_SHOT_COOKIE="name=value"`; `PEYRA_SHOT_RAIL=collapsed` sets the rail cookie. A keyboard
path is replayed and checked before the capture: `PEYRA_SHOT_FOCUS=<selector>` focuses an element,
`PEYRA_SHOT_KEYS="text:prof.leroy,Tab,Enter"` presses real keys (`wait:2000` lets a page the keys opened
arrive), `PEYRA_SHOT_EVAL=<js>` prints what
an expression returns (awaited); a Chrome that stops answering is given up after
`PEYRA_SHOT_TIMEOUT` milliseconds (60 000). The design gallery is <https://localhost:8143/_design> (with
`/_design/teacher` and `/_design/public`), development and test only: every Px component in every
state, in both shells.

The test database (`peyra_test`, and `peyra_test1`… for ParaTest through
`app:test:parallel-databases`) is described in `tests/README.md`, with the sequence CI replays.

Production needs both files, in this order (not deployed by the build agents; `docs/production.md`
is the operator's guide, and the server runs them through its own `deploy-prod.sh`, never in git):

```console
docker compose -f compose.yaml -f compose.prod.yaml build --pull
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

**Production sits behind the establishment's nginx, on another machine** (plan README § 9), which
terminates TLS. What the repository holds for it, each piece pinned by `ProductionConfigurationTest`:

- `compose.prod.yaml` serves **plain HTTP on one port, for every Host**: `SERVER_NAME=:80` and
  `auto_https off`, the ports `!override`n to HTTP alone, no database service (production uses
  Formatool's MariaDB server, base and user of its own). Never put the domain in `SERVER_NAME`:
  `frankenphp adapt` shows Caddy then listens on 443 even with automatic HTTPS off, and nothing
  answers nginx on 80.
- **`PUBLIC_URL`** (exported by the deploy script, required by `compose.prod.yaml`) gives
  `DEFAULT_URI` and Mercure's public address to `php` and `worker`; in development both still derive
  from `SERVER_NAME` and `HTTPS_PORT`. `PEYRA_VERSION` reaches both services the same way.
- **`TRUSTED_PROXIES`** (`framework.trusted_proxies`, the four `x-forwarded-*` headers): empty in
  development, `192.0.2.10` in `.env.test` (`TrustedProxyTest`). Without it the sign-in journal and
  the limiters see nginx, and links say `http`. The session cookie is `SameSite=Lax`, `secure: auto`,
  forced `true` under `when@prod`.
- The Caddyfile sends the base security headers on every answer (and drops `Server`); HSTS is
  `compose.prod.yaml`'s, through `CADDY_SERVER_EXTRA_DIRECTIVES` - on localhost it would stick.
- **`.env.prod.local.example`** names every variable production reads that Compose does not set:
  a new `%env()%` or `#[Autowire(env:)]` fails the test until it is added there.
- **Three body-size ceilings**: nginx's `client_max_body_size 64m`, PHP's 64M/65M
  (`frankenphp/conf.d/10-app.ini`), then the upload policy. PHP sits at nginx's height, or it drops
  a body nginx let through without a word (FrankenPHP answers 200 with HTML). Move them together.
- `FORMATOOL_URL` draws the avatar menu's « Formatool » entry and the forgotten-password link
  (Formatool's `/login/magic-link`: Peyra keeps no password); `PEYRA_PASSWORD_HELP_URL` overrides the
  latter (`App\Service\Identity\FormatoolLinks`). Empty: neither is drawn.
- The directory variables default to production's, Formatool's Samba AD (`sAMAccountName`, `group`,
  `objectGUID`); `.env.dev.local` sets the development OpenLDAP's `uid`, `groupOfNames`, `entryUUID`
  - an older local copy of it lacks the first two and nobody can sign in: copy them from the example.
- Migrations are not transactional (`doctrine_migrations.transactional: false`): MariaDB commits
  around every DDL statement anyway.
- **The S3 bucket is Terraform's**, `infra/terraform/` (step 0.8): a private bucket (public access
  blocked, ACLs disabled, SSE-S3, versioned, old versions expired, HTTPS only, `prevent_destroy`) and
  one IAM user whose policy is `s3:ListBucket` on the bucket's ARN and `s3:GetObject`/`PutObject`/
  `DeleteObject` on `bucket/<prefix>*`. Outputs are named after the `S3_*` variables
  (`terraform output -raw env_prod_local` prints the block). **Run by the operator from their own
  machine, with their AWS profile and a local state - never by an agent nor CI**: an agent never runs
  `plan`/`apply` nor reads `~/.aws`; CI only runs `fmt -check`, `init -backend=false` and
  `validate` in `hashicorp/terraform`. `.terraform.lock.hcl` is committed; state, `*.tfvars` and
  plans are gitignored, and `infra/` is dockerignored.

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
- The development worker reloads on a changed file under `src/`, `templates/` or `config/`, but not
  in a **directory created after `php` started**: its PHP keeps the old code (a new controller
  answers with the previous one, a template gets variables it no longer expects). `docker compose
  restart php` after adding a directory.

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
  A `<twig:block>` cannot sit inside a `{% if %}` (the lint reads an unexpected `endblock`): put the
  `if` inside the block. Inside a component's block, a prop of the component hides the page's variable of the same name
  (`rows` in a `Px:Table`, `label` or `error` in a `Px:Field`), and so does a variable the
  component's template sets (`classes` in `Px:Table`, a string there): name the page's variables
  apart (`class_rows`, `year_classes`). A selection of rows is `selection_controller.js` (boxes in
  the first column, `px-lotbar` in place of the filters once one is ticked).
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
  A held key's repeats never click (`event.repeat`). In a path of GET steps (Distribuer), each
  step's primary button answers `Ctrl+Enter` and the step's first field has `autofocus`.
  A CSRF token never sits in a form that can be submitted by GET: give it to the POST button as
  its `name="_token"` and `value` (only the submitter's pair travels).
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
- **Regulatory parameters are dated values** (millésimes), read at the dossier's date
  (`RegulatoryReader::at()`, see « Regulatory values and audit trail »).
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

| Login | Name | Groups | Enters as | After `app:dev:school`, what it shows |
|---|---|---|---|---|
| `prof.martin` | Jean-Pierre Martin | teacher, cg-1, cg-2 | teacher, **administrator** (bootstrap list) | every class of both years; Paramètres › Administration; every student's dossier (he teaches every class) and the common library's template, which he changes |
| `prof.durand` | Sophie Durand | teacher, cg-1 | teacher | « Mes classes » = BTS CG 1 2026-2027; Administration answers 404; the dossiers of the BTS CG 1 students, a 404 on the others' |
| `prof.leroy` | Marc Leroy | teacher, sio-1 | **refused** until authorised by name | the refusal of design-ux § 3.6, then entry once added |
| `lucas.bernard`, `manon.chevalier`, `theo.garnier`, `ines.morel`, `adam.roussel`, `chloe.fabre` | Lucas Bernard, Manon Chevalier, Théo Garnier, Inès Morel, Adam Roussel, Chloé Fabre | student, cg-1 | students of first year | placed in BTS CG 1 2026-2027 at their first sign-in; their Menuiserie Vasseur after `app:dev:dossiers` |
| `yasmine.haddad`, `louis.perret`, `camille.dufour`, `nathan.mercier`, `lea.boyer`, `sacha.lambert` | Yasmine Haddad, Louis Perret, Camille Dufour, Nathan Mercier, Léa Boyer, Sacha Lambert | student, cg-2 | students of second year | placed in BTS CG 2 2026-2027 at their first sign-in |
| `jules.martel` | Jules Martel | student, cg-1, cg-2 | student with two contradictory groups | « à placer », with the amber banner on « Mes dossiers » |
| `enzo.petit` | Enzo Petit | student, sio-1 | **refused** | « Peyra est réservé aux étudiants et aux enseignants du BTS CG… » |

A wrong password, or an unknown login, reads « Identifiant ou mot de passe incorrect. » for
everybody. `/_dev/login-as/<login>` (development and test) skips the password, never the rule.

The service account is `cn=svc-peyra,ou=service,dc=peyra,dc=lan`. After changing the tree:
`docker compose rm -sf openldap && docker volume rm peyra_ldap_data && docker compose up -d openldap`.

### Years, classes and placement (built at step 0.5)

Conception § 3 bis, held by code in `src/Service/Identity/`:

- `SchoolYear` (exactly one current, chosen by an administrator; the years before it are history:
  `SchoolClassVoter` lets every teacher read every class and place students only in the current
  year or a later one), `SchoolClass` (per year, level 1 or 2, `nextClass` in a later year),
  `Enrolment` (one per student and year, UNIQUE; origin directory / manual / promotion),
  `TeacherClass`, `DirectoryGroupMapping` (per year, group → class).
- **Placement at creation, once**: `AccountProvisioner` calls `InitialPlacement` for a new student
  only; the rule is the pure `PlacementRule` (one class designated by the groups, else « à placer »
  with its reason, kept on `User::$initialPlacement`). The groups read then are
  `User::$groupsAtCreation`; a later sign-in rewrites `directoryGroups` and never the class.
- **`Enrolments` is the one door of placement by hand** (place, move, remove, departure), every
  gesture written to the administration journal (`AdministrationEvent::$detail` says what). Removing
  deletes the year's enrolment (the journal keeps it); nothing touches an account or a dossier.
- « Sorti de la filière » (`User::$departedAt`) takes a student out of « À placer »; placing them
  or their next sign-in brings them back. It deletes nothing.
- `Promotion` / `PromotionPlan`: the passage de classe in three GET steps then one POST; a student
  already enrolled in the target year is left as is, so the gesture can be made again.
- `SchoolYears::create()` may take over the previous year (classes by name and level, mapping,
  and the next class of last year's first-year classes when the new year has a single second-year
  class); teachers are never taken over. `YearPreparation` lists what the rentrée still needs.
- `User::$sortName` is « Nom Prénom » (`sn givenName`): lists of people show and sort on it.
- `app:dev:school` (dev and test only) builds 2025-2026 and 2026-2027 with their classes; students
  are placed by their own first sign-in, as in production. Classes are the institution's: they are
  never part of the demonstration dossier (`app:demo:install`, lot 1).

## Dossier, context and rights (built at step 1.1)

Conception §§ 3 bis and 4, held by code in `src/Entity/Dossier/`, `src/Service/Dossier/`,
`App\Doctrine\Filter\DossierFilter` and `App\Security\Voter\DossierVoter`:

- **`Dossier`** (global): its kind (`DossierKind`: template, student, group, snapshot), the company
  embedded (`Company`, replaced whole through `CompanySheet`, which names every fault under its
  field), its **date** (`dossierDate`), its owner (the student; a template's author; nobody for a
  group or the **common library**), the school year it was made in (« Mes dossiers » groups by it),
  its origin (`sourceTemplate`, `distributedBy`).
- **`DossierOwned`** + `DossierOwnedTrait`: every business row carries a mandatory `dossier_id`.
  `DossierOwnedEntitiesTest` makes every entity take a side - DossierOwned, or named in its closed
  list of global entities with the reason. **Who enters a dossier (`DossierMember`, `DossierShare`)
  is global**: read by the voter before any context, and never copied with the dossier.
- **The filter `dossier`** (enabled in `config/packages/doctrine.yaml`) narrows every ORM read of a
  DossierOwned row to the context's dossiers, and **throws `DossierContextMissing` without one**.
  `App\Service\Dossier\DossierContext` is its only writer: `enter()`, `within()` (another dossier
  for a moment), `across()` (several dossiers the caller may read: listings), `reset()` between two
  requests of the worker. A command enters the dossier it works on. Only ORM queries are filtered:
  DBAL goes around it, so business code never reads a DossierOwned table through DBAL.
- **`App\Security\DossierRouteListener`** (kernel.request, after the firewall): a route with
  `{dossier}` loads it, asks `DossierVoter::VIEW` - a refusal is a 404 - then enters its context
  and hands the controller its `Dossier $dossier` argument. It notes a student's visit (« dernier
  passage », every five minutes at most) and the first visit after a date move
  (`Dossier::$dateMoveUnseen`: the chip flashes once).
- **`DossierVoter`**: VIEW, EDIT, SET_DATE. A student's or a group's dossier: its student or
  members (VIEW, EDIT, never SET_DATE); the teachers of a class one of them is enrolled in **this
  school year** (everything, every year's dossiers - `DossierHolders::teachesThisYear()`); the teacher
  who distributed it (VIEW). A template: its author; its shares (VIEW, plus EDIT and SET_DATE when
  « modification »); the common library read by every teacher, changed by administrators. **No
  administrator bypass** for a student's dossier; a snapshot is never opened.
- **`DossierClock::today()`** is the date of the context's dossier; `DossierDates::move()` is the
  one door that moves it (a `DossierDateChange` row, append-only, and the audit trail).
- `Exercise` and monthly `Period`s are opened by `Exercises::open()` (bounds from the pure
  `PeriodPlan`: calendar months cut by the exercise's bounds, 1 to 24 months, no overlap).
- **Screens**: `/d/{dossier}` (dashboard), `/d/{dossier}/company` (Système d'information › Société,
  F10), POST `/d/{dossier}/date`. `templates/dossier/_layout.html.twig` is the PGI inside a dossier:
  `dossier_shell(dossier)` (`DossierShell`) gives the switcher (the student's dossiers by year; a
  teacher sees that student's), the date chip and its panel (`popover_controller.js`: day in full,
  exercise, period, the last three moves - never a real date - and, for SET_DATE, « Avancer la
  date »), and the teacher's ribbon (`Px:ContextBar`, violet; `tone="template"` gold). Child
  templates set `rail_current` and `dossier_title`; the shell's `view` exists only inside blocks.
- `fixtures/demo/company.yaml` is the demonstration dossier's first file (`DemoCompanyFile` checks
  it whole); the dossier grows there with the steps.

## Plan of accounts and journals (built at step 1.2a)

Lot 1, step 1.2a, held by code in `src/Service/Accounting/Chart/`, `src/Service/Accounting/Journal/`
and `src/Entity/Accounting/`:

- **Reference charts are data, `fixtures/pcg/`**: `charts.yaml` names each chart (code, regulation,
  `applies_from`, source, date read, `verified`) and its CSV (`numero;intitule;classe;niveau;verifie`),
  read whole by `ReferenceCharts` (a fault names its file and line). Three: **PCG 2026** (règlement
  ANC 2014-03 consolidated at 1 January 2026, i.e. 2025 plus regulation 2024-07: 1673, 16711, 16712,
  1682 created, 1675 and 16718/16748/16758 gone, ten labels changed - checked on the ANC's PDF),
  **PCG 2025** and **PCG antérieur**. `forExerciseStartingOn()` is the latest whose date has come.
- **A dossier's plan is a copy** (`ChartInstaller`, into an empty plan only): every reference account
  of three digits and more, **padded to six digits** (« 601 » → « 601000 »; no two collide), with
  `Dossier::$chartCode`. The one- and two-digit levels stay headings (`ReferenceChart::groupOf()`,
  « 60 · Achats (sauf 603) »), never accounts. A plan still empty offers « Installer le PCG 2026 ».
- **`Account`** (DossierOwned): number of **6 to 8 digits**, class 1 to 8 (`AccountNumber::fault()`),
  label, `lettrable`, `collective`, origin (reference, created, imported). **Its class and its nature
  are read from the number, never typed** (`AccountNature::of()`: 512/514-517 are banks, 53 cash, 28,
  29, 39, 49, 59 depreciation…); lettrable and collective have defaults read from the number
  (`AccountNumber::isLettrableByDefault()`, `isCollectiveByDefault()`), only a class-4 account is
  collective. **A third party is not an account**: « 401SCIE » is a third party's code on a
  collective account (« Third parties » below) - an account number has digits only.
- **`AccountSheet` is the one door by hand** (check field by field, create, update, delete), every
  gesture in the audit trail (subject: the dossier). A number that subdivides no account of ≥ 2
  digits of the dossier's reference chart is refused at G and B, signalled at A, silent at 0
  (`Assistance`); an account already outside it keeps its number. An account a journal names as counterpart is not deleted and stays of its
  nature. Once it carries entries, see « Accounting engine » below for what it keeps.
- **`Journal`** (DossierOwned, `JournalSheet` the one door): code of 1 to 6 capitals or digits, type
  (`JournalType`), a counterpart account for bank (a bank account) and cash (a cash account) journals
  only, one à-nouveaux journal at most. A cash journal needs a 53… account the dossier creates (the
  PCG has 53 at two digits only).
- **The account picker** (`Px:AccountPicker`, `account_picker_controller.js`, design-ui § 4.6): a
  text field holding the number; from the second character, or on **F4**, the list
  (`AccountSearchController`, 50 at most, `nature=` narrows) grouped under the headings, typed digits
  in `<mark>`, ↑ ↓ Entrée Échap; Entrée moves to the next field. The search is `AccountSearch`:
  digits = start of the number, words = label without accents nor case - the plan's list uses it too.
- **Addresses**: `/d/{dossier}/accounting/accounts[/{number}]`, `…/journals[/{code}]`, `…/accounts/
  search`, `…/accounts/export.csv`, `…/accounts/import`. Screens under Comptabilité › Paramètres
  comptables (module tab), its views as `Px:Tabs` (`accounting/_settings_tabs.html.twig`).
- **CSV both ways** (`App\Service\Shared\Csv\CsvText`): written UTF-8 with a BOM, `;`, CRLF, `oui`/
  `non`; read with or without BOM, Windows-1252 recognised, separator guessed (`;`, tab, `,`), blank
  lines skipped, a header optional. `ChartImport` adds what is missing, updates existing accounts
  only when asked, and **writes nothing when one line is refused** (the report names each line).
  The import form posts outside Turbo (`data-turbo="false"`): the report is the answer to the post.
- The demonstration's own accounts and journals: `fixtures/demo/accounts.yaml`, `journals.yaml`
  (`DemoChartFile`); a demonstration account over a reference one keeps its origin under its label.
- **Guards of an account that carries third parties** (step 1.2b, `AccountSheet`, `ChartImport`):
  its « collectif » box stays as it is (their auxiliary codes exist because it is collective), its
  number keeps a nature their kinds accept, and it does not leave the plan.
- **An account number used as an array key becomes an integer** (« 512100 » is a numeric string):
  `$map['512100']` then reads, but PHPStan and `array_keys()` see ints and a strict comparison with
  the number fails. Look an account up by its number through the repository or a loop.

## Third parties (built at step 1.2b)

Lot 1, step 1.2b, held by code in `src/Entity/ThirdParty/`, `src/Service/ThirdParty/` and
`src/Controller/ThirdParty/`:

- **`ThirdParty`** (DossierOwned): code (« C0003 », 1 to 12 capitals or digits, unique; a new one
  gets its kind's letter and the next number, `ThirdPartyCode::next()`), kind (`ThirdPartyKind`:
  customer, supplier, employee, other), name, private person or not, SIREN and VAT number, address
  and contact, **structured payment terms** (`PaymentTerms`, embedded: `PaymentTermKind` + N days
  or day J; `dueDate($invoiceDate)` is the one reckoning, read at 2.7 - « 45 jours fin de mois » of
  the prepared data is « fin de mois + 45 jours »), payment method (`PaymentMethod`), a note.
- **Its account**: a collective (401000, 404000, 411000…) **with its auxiliary code** (« 401SCIE »,
  2 to 17 capitals or digits with at least one letter, unique in the dossier; left empty, one is
  proposed from the collective and the name, `AuxiliaryCode::suggest()`), or a general account
  (431000, 455100, 164100 for a lender) without one, or no account at all (a shop paid by card).
  The kind decides the natures accepted (`ThirdPartyKind::accountNatures()`). `ThirdParty::keepOn()`
  refuses an auxiliary code without a collective, or a collective without one.
- **`ThirdPartyRules::check()` is the one reading** of a third party, a pure function over three
  callables (the plan, who holds a code, who holds an auxiliary code): the fiche (`ThirdPartySheet`,
  audit trail), each CSV line (`ThirdPartyImport`) and the demonstration's file
  (`DemoThirdPartyFile`) go through it.
- **The account picker finds auxiliary codes**: when the text holds a letter and no `nature`
  narrows it, `AccountSearchController` adds a « Tiers » group (auxiliary code, name · collective)
  after the accounts - what the entry grid's Compte column takes (design-ux.md § 5.2, step 1.4).
  A third party on a general account is entered by its account.
- **Screens**: Comptabilité › Paramètres comptables › Tiers (`/d/{dossier}/accounting/third-parties`,
  filtered by kind and by `account`, searched by code, auxiliary code or name), the fiche
  (`…/{code}`, F10, delete in two steps; the due date of an invoice dated the dossier's date is
  shown), `…/export.csv` and `…/import` (header required, at least `code;nature;nom`; without a
  `compte` column a customer goes on 411000 and a supplier on 401000; one refused line writes
  nothing). Ventes › Clients and Achats › Fournisseurs (lot 2) will read the same entity.
- A form refused (422) focuses its first field in error (`focus_error_controller.js`, design-ux.md
  § 6.1), on the fiches of a third party, an account, a journal and the company.

## Accounting engine (built at step 1.3)

Conception § 5, held by code in `src/Entity/Accounting/`, `src/Service/Accounting/Ledger/`,
`src/Service/Accounting/Gateway/` and `App\Doctrine\LedgerGuard`:

- **`Entry`** (journal, exercise and period of its date, date, `document` - the « pièce », the FEC's
  PieceRef -, label, `EntryState` draft/validated, `number` at validation, `validatedOn` = the
  dossier's date then, `EntryOrigin`, `source` module, `reversalOf`) and **`EntryLine`** (account,
  third party, label, `debit`/`credit` in cents, due date, lettering `letter` for step 1.6). Both
  DossierOwned.
- **`Ledger` is the one door** (`LedgerDoorTest`: nothing else does `new Entry`/`new EntryLine` nor
  calls the entry's `ledger…` methods): `draft()`, `update()`, `discard()` (brouillard only),
  `validate(list)` / `validateDrafts(?journal, ?period)` (all or nothing), `reverse(entry, on)`.
  Each gesture is checked whole by **`EntryRules::check()`** (pure, four callables: journal, period
  at a date, account, third party; faults under `journal`, `date`, `document`, `label`, `lines`,
  `line.<i>.<field>`), recorded in the audit trail and **flushed by the Ledger itself** in one
  transaction; a refusal is `EntryRefused` (`errors` by key) and writes nothing.
- **An entry is balanced or does not exist**, drafts included (two lines at least, one positive
  side per line). A closed period or exercise refuses a draft, a validation and a reversal date.
- **Numbering**: at validation, per journal and per exercise, after `MAX(number)` read under a
  pessimistic lock on the journal row (journals locked in id order), in date then draft order;
  `UNIQUE(journal, exercise, number)`. Shown « ACH-2026-00012 » (`getNumberLabel()`).
- **Validated is final**: `LedgerGuard` (onFlush) refuses any change or deletion of a validated
  entry and of its lines (except `EntryLine::MUTABLE_WHEN_VALIDATED`, the letter), and any entry
  flushed unbalanced - whoever wrote it. A reversal is the mirror entry (same journal, document,
  lines swapped), validated at once, `reversalOf` set; once per entry, never of a reversal.
- **Third parties on lines**: a collective account's line names a third party with an auxiliary
  code whose kind accepts the account's nature (its own collective, or 416000/419100 for a
  customer); a general account's line may name only the third party kept on it (455100). A due
  date only on a lettrable account.
- **`LedgerReader`**: every figure is an aggregate query over the lines - `accountTotals()`,
  `accountBalance()`, `thirdPartyTotals()`, `thirdPartyBalance()`, `movements()` (running balance),
  `trialBalance()`, `generalLedger()` - over a window of days and an `EntryScope` (validated only,
  or with the brouillard). The screens pass the start of the exercise (`exerciseStart()`): an
  exercise opens on its own à-nouveaux. No column holds a balance (`NoStoredBalanceTest`).
  `Totals::balance()` is debit minus credit; the `balance` Twig filter writes « D »/« C ».
- **`AccountingGateway::submit(dossier, EntryProposal)`** is the modules' door (`ModulesDoNotPostTest`:
  only `Service/Accounting/`, `Controller/Accounting/` and the demonstration name the Ledger). It
  applies `AccountingBehaviours` (Propose, LetEnter, Post - Post drafts in the brouillard, it never
  validates: lot 2's rule); `ProposeByDefault` answers until the missions (2.9). A module whose screen
  shows its proposal first (the pièces) asks `behaviourFor()`, then hands the student's entry to
  `record()`.
- **What carries entries keeps its shape**: an account with validated lines keeps its number and
  stays, with any line keeps its « collectif » box (the plan's import too); a journal with entries
  keeps its type and stays, with validated ones its code; a third party named on a line keeps its
  account and auxiliary code and stays (its import too). The F4 picker shows each account's and
  third party's balance at the dossier's date; the account fiche its balance; the third parties'
  list and fiche their « Encours ».
- `fixtures/demo/entries.yaml` (`DemoEntryFile`): the demonstration's entries, written through the
  Ledger by `DemoInstaller` (drafted, then validated, by batches that leave the unit of work): the
  opening balance (AN-2026-001, 67 lines, 459 260,31), then the first quarter (step 1.10a; January
  is operations-2026.md § 2.2 whole, February and March are generated by the plan's
  `donnees/demo/script-de-preparation/trimestre.py`, which rewrites the file - edit the script, not
  the file; the balances of § 2.5 and § 4 are pinned by `DemoInstallCommandTest`).
- **Deleting a dossier** by SQL fails on the intra-dossier `RESTRICT` keys (journal → account,
  line → account…): `DossierEraser` (« Templates, library and the dossier copier ») deletes table
  by table in the reverse of the copier's order - never delete a dossier any other way.

## Keyboard entry (built at step 1.4a)

Lot 1, step 1.4a (design-ux.md §§ 4.4 and 5.2, design-ui.md § 4.8), held by code in
`src/Service/Accounting/Entry/`, `src/Controller/Accounting/EntryGridController.php`,
`EntryController.php` and `assets/controllers/entry_grid_controller.js` (+ `assets/entry_grid/model.js`):

- **Comptabilité › Saisie** (`/d/{dossier}/accounting/entry?journal=ACH&period=2026-01-01`, the
  rail's Comptabilité): the grid of a journal and a month - Jour · Pièce · Compte · Intitulé ·
  Libellé · Débit · Crédit · Échéance. The journal and month last entered are taken again (session,
  per dossier); `Alt+J` / `Alt+P` focus their selects. **The browser draws the grid** from the
  page's JSON (`grid`: the month's entries and the person's pending work; `lookup`: the plan and the
  third parties with their balances): nothing a keystroke needs asks the server - Sage's speed.
- **The Compte cell** takes a number (digits filter by start, the list of 8 under the cell, `Entrée`
  takes the first) or an auxiliary code (« 401SCIE », letters search codes and names); `F4` lists the
  whole plan by class (`←` `→`); `Alt+Inser` creates the account typed (POST `…/entry/account`,
  `AccountSheet`). A collective typed by number (419100) turns the Intitulé cell into the third
  party's. **`Entrée` goes to the next cell to fill** (a new entry's first line visits day and
  document; carried day, document and label are skipped; a complete line opens the next line, a new
  entry once balanced, pièce incremented); `Tab` visits every cell; `+` in an amount balances the
  entry; `Ctrl+D` copies the cell above; `Inser`, `Ctrl+Suppr` insert and delete a line;
  `Ctrl+↑ ↓` jump by entry; `Échap` closes the list, then undoes the cell, then offers to abandon the
  entry's changes (a bar inside the grid); `Ctrl+F6` leaves the grid.
- **What is typed is never an Entry until it balances**: each line left keeps the person's pending
  work (`PendingEntry`, one per journal, month and author, `PendingEntries` the one door, revision
  against stale tabs → 409) as `GridGroup`s; **F10** (`JournalGrid::save()`) writes to the
  brouillard every group the Ledger accepts and no blocking control stops (new → `draft()`, a draft
  changed → `update()`, a draft whose lines were all deleted → `discard()`), the rest stays pending
  and is drawn amber (« brouillon incomplet »). Leaving the screen with an unbalanced entry asks
  (keep it / delete it / stay). Others' pending work in the same grid is announced in a banner
  (the teacher sees the student's). A `PendingEntry` is DossierOwned: step 1.8 decides what the
  copier does with it.
- **`GridDrafts`** (pure) is the grid ↔ Ledger mapping: auxiliary code → collective + third party;
  the entry's label is its first line's; a purchase or sale line on a lettrable account naming a
  third party without a due date gets `PaymentTerms::dueDate()`; **a bank or cash journal's
  counterpart line is automatic** (one line on the journal's account balancing the lines typed,
  drawn read only; `linesOf()` strips it when an entry comes back to the grid).
- **`EntryControls`** (pure) at the dossier's assistance level (`AssistanceLevels`, see « Référentiel
  and assistance levels »; enum `AssistanceLevel` G/B/A/0): account unusual for the
  journal (`isUnusual()`), date outside the month or after the dossier's date, document already in
  the journal in the exercise. A at warning, G and B blocking (F10 and the validation hold the
  entry), 0 none. `JournalGrid::check()` runs EntryRules over an in-memory `GridContext` (one read
  of the plan, third parties, months and documents per request).
- **Ctrl+Entrée** (`JournalGrid::validate()`): F10, then the month's brouillard of the journal
  validated (all or nothing), but the drafts being changed and, at G and B, those a control stops;
  after the confirmation bar in place of the head's actions (design-ux.md § 6.2).
- **An entry's fiche** (`/d/{dossier}/accounting/entries/{id}`, the grid's mark column links to it):
  a draft is validated (confirmation bar, `confirm_bar_controller.js`), deleted (two steps), changed
  in the grid (`?entry=ID` focuses it) or duplicated; a validated one is reversed at a date (the
  dossier's by default) - « Contrepasser et ressaisir » also puts a copy in the grid - or duplicated.
  A duplicate is a pending group of the entry's grid (`JournalGrid::append()`, `?entry=-1`).
- JSON posts carry the CSRF token `entry-grid-<dossier>` in `X-Peyra-Token`; a closed month or
  exercise draws the grid read only and refuses every post (404).
- Pitfalls met: replacing the `tbody` that holds the focus fires `focusout` in Chrome - the
  controller sets `rendering` while it redraws; `bin/shot`'s `text:` step is split at commas
  (type « 3380.10 », not « 3380,10 »), and `+` is the key name for the plus key.

## Entry templates and guided entry (built at step 1.4b)

Lot 1, step 1.4b (design-ux.md § 3.2, « Modèles d'écriture », « Saisie guidée »), held by code in
`src/Entity/Accounting/EntryTemplate*.php`, `src/Service/Accounting/Template/`,
`TemplatesController`, `GuidedEntryController` and the F6 half of `entry_grid_controller.js`:

- **`EntryTemplate`** (DossierOwned): code (1 to 12 capitals or digits, unique), label, journal (FK
  `RESTRICT`: `JournalSheet::deletionRefusal()` names the templates), `base` (`TemplateBase`: the
  call asks the amount HT or TTC), `vatRate` (percent as exact decimal text, « 20 », « 5.5 » -
  `VatRate`; the template's author writes it, no rate in the code). **`EntryTemplateLine`**: the
  Compte and Tiers cells **as the grid types them** (text, not keys: an account number or an
  auxiliary code, looked up at each call - `TemplateSheet::faultOf()` says « À revoir » when one no
  longer resolves), label (`{mois}` becomes the entry's month), side (`EntrySide`), amount
  (`TemplateAmount`: HT, TVA, TTC, fixed, balance, to type), fixed amount.
- **`TemplateRules::check()`** (pure, four lookups) is the one reading: fiche, demonstration file;
  faults under `code`, `label`, `journal`, `base`, `rate`, `lines`, `line.<i>.<field>`. Two lines
  at least, one in a bank or cash journal, whose counterpart is never a line (the grid adds it);
  at most one balance line; a VAT line needs a rate above 0; the lines must balance, tried on
  1 000,00 at the template's rate, unless a line is to type or a balance line or the counterpart
  closes them. **`TemplateSheet`** is the one door (audit trail: `EntryTemplate*` actions).
- **`TemplateComputation`** (pure) is the one place HT, TVA and TTC are computed: from HT, TVA = HT ×
  rate / 100 rounded to the cent half up, TTC = HT + TVA; from TTC, TVA = TTC × rate / (100 + rate)
  half up, HT = TTC − TVA; a balance line takes what balances the others on the side that does it.
  **`TemplateCall::lines()`** turns a template into `GridLine`s for a date, an amount, a rate and the
  third party a collective typed by number asks (`asksThirdParty()`).
- **F6 in the grid** (and the head's « Modèle F6 »): the journal's templates (`grid.templates` in the
  page), filtered, Entrée; the amount and the rate when the template asks them; POST
  `…/entry/template` answers the lines, laid after the current entry's typed lines (a validated
  entry's call goes to the new entry at the end); the focus goes to the first cell still to fill
  (the third party of a collective, an amount to type), or to the next entry when it balances;
  nothing is written before the line is left (pending work, then F10). Échap goes back to the list,
  then closes.
- **Saisie guidée** (`/d/{dossier}/accounting/entry/guided`, gabarit F, the view « Guidée » next to
  « Par journal », `accounting/_entry_tabs.html.twig`): templates whose every line is computed
  (`GuidedEntry::offers()`), date, document, third party, amount, rate; the entry previewed in the
  Turbo frame `guided-preview` (`…/guided/preview`, refreshed by `guided_entry_controller.js` as the
  form changes), checked by `JournalGrid::check()` like the grid; F10 drafts it through the Ledger,
  then the page offers the next document. **Open at levels G and B, and always to a teacher**
  (`Assistance::opensGuidedEntry()`); a student at A or 0 gets a 404 and no tab, until a mission's setting
  (step 2.9). For whoever may EDIT the dossier.
- `fixtures/demo/templates.yaml` (`DemoTemplateFile`): LOYER, CBAIL, HONOR, ACHAT20, VENTE20,
  FRAISBQ2, REGLFOUR, installed by `DemoInstaller` through `TemplateSheet`.
- A Twig variable `{% set %}` outside a component's `<twig:block>` is not seen inside it: set it in
  the block (`_guided_preview.html.twig`).

## Subscriptions (built at step 1.4c)

Lot 1, step 1.4c, held by code in `src/Entity/Accounting/EntrySubscription.php`,
`src/Service/Accounting/Subscription/` and `SubscriptionsController`:

- **`EntrySubscription`** (DossierOwned): label, template (FK `RESTRICT`: `TemplateSheet::deletionRefusal()`
  names the subscriptions that call it), the call's amount and rate (when the template asks an
  amount) and third party (when a collective line asks one, as the grid types it), day of the month
  (1 to 31, a day the month lacks is its last), first month, number of occurrences (1 to 120),
  document pattern (`{AAAA}`, `{MM}`, `{N}` the rank; `{MM}` or `{N}` required, 40 characters once
  filled), target (`SubscriptionTarget`: the grid's pending work or the brouillard) and the count of
  occurrences generated (column `generated_count`: `generated` is a MariaDB keyword).
- **`SubscriptionSchedule`** (pure) is when and what: `dateOf()`, `documentOf()`, `occurrences()`,
  `dueUntil()`, `next()`. **`SubscriptionRules::check()`** (pure, four lookups) the one reading; the
  brouillard asks a template whose every line is computed (`GuidedEntry::offers()`); once an
  occurrence is generated the first month is fixed and the count never drops below it.
  **`SubscriptionSheet`** the one door (audit trail, `faultOf()` « À revoir »).
- **`SubscriptionRun::generate(dossier, until, actor, now)`**: `until` is the dossier's date, never
  the clock. Each occurrence is `TemplateCall::lines()` then `JournalGrid::append()` (pending work,
  a person's own: a command generates into the brouillard only) or `JournalGrid::check()` +
  `Ledger::draft()`; one transaction per occurrence with the subscription's row locked, the counter
  moving with the entry (two tabs never make one twice). A refused occurrence (closed month, outside
  the exercises, a rule, a blocking control) stops its subscription there and says why
  (`SubscriptionOutcome::$faults`, shown as alerts above the list).
- **Screens**: Paramètres comptables › Abonnements (`/d/{dossier}/accounting/subscriptions`, next
  occurrence, « n à générer »; « Générer jusqu'au » the dossier's date, Ctrl Entrée after the
  confirmation bar that counts the entries by target), the fiche (`…/{id}`, F10; the call's fields
  follow the template chosen, `subscription_form_controller.js`; every occurrence listed with its
  state), `…/new`, `…/{id}/delete` (the entries it made stay).
- `fixtures/demo/subscriptions.yaml` (`DemoSubscriptionFile`): the rent (10 from March) and the
  leasing (30 from March), into the brouillard, generated up to the dossier's date at install -
  March's two are drafts of the demonstration.

## Consultation, search and brouillard (built at step 1.5a)

Lot 1, step 1.5a (design-ux.md §§ 3.2 and 6.6), held by code in `src/Service/Accounting/Consultation/`,
`ConsultationController`, `EntrySearchController`, `BrouillardController` and `App\Service\Shared\Export\`:

- **Comptabilité gains two module tabs**, Brouillard and Consultation (the editions come at 1.5b);
  Consultation has two views (`accounting/_consultation_tabs.html.twig`): « Compte ou tiers » and
  « Recherche d'écritures ».
- **Consultation of an account or a third party** (`/d/{dossier}/accounting/consultation?account=…`):
  `AccountStatements::subject()` reads what is typed - a number, an auxiliary code, a third party's
  code when it has none (« A0001 ») -; `statement()` reads its movements through `LedgerReader`
  (`movements()`, or `thirdPartyMovements()`: every line naming the third party, 411000 and 419100
  alike) over a `ConsultationWindow` - the exercise up to the dossier's date by default, a fault
  said and the defaults read - brouillard included or not (`scope=validated`). A window that starts
  after its exercise opened opens on « Solde au » the eve (`AccountStatement::$opening`); every page
  after the first opens on the balance carried (« Report », `page()`). A collective account shows
  each line's third party, a third party each line's account. The filter bar's picker submits on
  Entrée (`data-account-picker-submit-value`).
- **Recherche d'écritures** (`/d/{dossier}/accounting/entries`) is also the journal's interrogation
  (`?journal=ACH&period=2026-01-01`, the grid's « Interroger le journal »): `EntrySearchCriteria`
  reads state, journal, month (`period`) or `from`/`to`, account or third party, exact amount (a
  line's debit or credit), text (document or a label); `EntrySearch` pages entries newest first
  (the Date header sorts, `dir=asc`), counts and adds the whole filter, counts by state for the
  chips. Its value filters sit on a second line of the bar (`px-filters__values`).
- **Brouillard** (`/d/{dossier}/accounting/brouillard`): `Brouillard::rows()` checks each draft as
  the grid does (`JournalGrid::check()` in a context per journal and month): a fault or, at G and B,
  a blocking control makes it `blocked` - no box to tick, the reason in the label -; a draft the
  person is changing in the grid is held back like the grid's Ctrl Entrée does. `validate()` is the
  Ledger's all or nothing over the ticked drafts, the held ones counted (`BrouillardOutcome`). Chips
  count by journal and month; the pending work (not entries) is named above with its grid.
- **From the balance to the detail, and back**: every line leads to its entry with `?return=` the
  page's address (`ReturnPath::here()`, its own `return` dropped); the entry offers « Revenir »
  only to a path of the same dossier (`ReturnPath::of()`). The entry's accounts, the account and
  third party fiches, the encours and the balances lead to the consultation. **F5** in the grid
  opens the line's account in a drawer (`…/consultation/peek`, balance at the dossier's date and the
  last eight movements, « Consulter » with the way back to the grid).
- **Spreadsheet exports**: `ExportTable` (columns typed by `ColumnKind`: text, code, amount, debit
  or credit cell, day) and `TableExport::csv()` / `xlsx()` / `response()` (openspout 5, MIT). CSV
  amounts are « 1234,56 » / « -138,00 », XLSX amounts numbers formatted `#,##0.00` - the only place
  cents become a float, at the file's boundary -, codes text, days dates. `Px:ExportMenu` offers both
  (`…/export.xlsx`, `…/export.csv` with the list's filters, `data-turbo="false"`). `EntryExport` is
  one row per line. The plan's and third parties' CSV stay their import formats.
- `rows_controller.js`: Espace ticks the row's box; Entrée opens the row only without a modifier
  (Ctrl Entrée is the screen's engaging gesture). `px-u-fill` is the label cell that takes the room
  left and is cut there.

## Editions (built at step 1.5b)

Lot 1, step 1.5b (design-ux.md § 3.1, gabarit R; design-ui.md §§ 3.6 and 4.18), held by code in
`src/Service/Accounting/Edition/`, `src/Controller/Accounting/Edition/` and `templates/accounting/edition/`:

- **Comptabilité › Éditions**, a module tab with five views (`accounting/_edition_tabs.html.twig`):
  Journaux, Grand livre, Balance (générale), Balance auxiliaire, Balance âgée. Each is a screen
  (`/d/{dossier}/accounting/editions/<view>`), its PDF (`<view>.pdf`, « Aperçu PDF », inline, A4
  landscape) and its spreadsheet (`<view>/export.{csv|xlsx}`, `EditionExports`: one row per line,
  no subtotal). One controller per view, `EditionControllerTrait` for the scope, the kept
  parameters and the PDF answer. A state is never paginated (design-ux § 6.6).
- **`EditionWindow`**: an edition **holds in one exercise** (an exercise opens on its own
  à-nouveaux): the exercise of the dossier's date up to that date by default; a window across two
  exercises, a day no exercise holds, a bound that is not a day is said and the defaults are read.
  A window starting after the exercise opens on what each account carried the eve (`hasOpening()`,
  `eve()`). `upTo()` is the aged balance's (its exercise's first day up to a day).
- **`AccountRange`**: « Du compte » « au compte » are numbers or their start, both included (« 4 »
  to « 4 » is class 4); letters or a backwards range are said and the whole plan is read.
- **Readings**: `JournalEdition` (entries by journal in code order, by month, subtotals);
  `GeneralLedgerEdition` (LedgerReader's general ledger in the range); `TrialBalanceEdition` and
  `ThirdPartyBalanceEdition` return a `BalanceReport` - groups (a class, a kind of third party)
  of `BalanceLine`s: the eve's balance, the movements, the closing balance split into a debit and
  a credit column. **A total adds the debit balances and the credit balances apart**
  (`BalanceLine::of()`/`plus()`), never the net: the general total's two columns are equal when the
  books balance (the status bar says « Balance équilibrée »).
- **The aged balance** (`AgedBalanceEdition`, prepared for lot 2's follow-up): customers (debit owed
  to the company) or suppliers (credit owed by it), at a day. `AgedBalance::age()` is a pure
  function over a third party's lines: the balance is made of its **newest** lines on its side
  (payments settle the oldest first), the last one taken in part, each part in the `AgeBand` of its
  due date - the entry's date without one -: not due, 1-30, 31-60, 61-90, more than 90 days. No
  lettering needed; `LedgerReader::linesByThirdParty()` reads the lines.
- **Every amount leads to the consultation** of its account or third party over the state's window
  (`_drill.html.twig`, `.px-table__drill`: the figure keeps its ink, the link shows on hover and
  focus), `return` back to the state; the first link of a row is what Entrée opens.
- **PDF**: `templates/accounting/edition/pdf/*` extend `doc/_report.html.twig`, whose head is now
  `report_header` (a `ReportHeader` from `Editions::header()`, or an array for the gallery): the
  company left, the title centre on one line, on the right the exercise and the dossier's date then
  the period, the scope (« brouillard inclus » / « écritures validées ») and the selection; the
  student at the foot (`Editions::student()`, a student's dossier only). The margin boxes carry
  explicit widths (30 / 34 / 36 %): without them Chromium squeezed the title onto two lines.
  `doc.css` adds `px-doc__group`, `px-doc__subtotal`, `px-doc__dim`, `px-doc__entryhead`; a code
  cell stays on one line. Each PDF of the demonstration takes under a second (design-ux § 6.4's
  two seconds hold without a background job).

## Global search (built at step 1.5c)

Lot 1, step 1.5c (design-ux.md §§ 2.1 and 4.4, design-ui.md § 4.6), held by code in
`src/Service/Dossier/Search/`, `App\Controller\Dossier\SearchController`,
`App\EventListener\RecentScreensListener`, `Px:CommandPalette` and `command_palette_controller.js`:

- **`Ctrl K` inside a dossier** (the student's and a teacher's in it): the top bar's trigger
  (`data-px-open="px-cmdk"`, `data-px-shortcut="Ctrl+K"`) opens a modal `<dialog>` at 12vh, 560 px; each
  key asks `GET /d/{dossier}/search?q=…&here=…` 150 ms after the last one, answered as the Turbo Frame
  `px-cmdk-results` (`target="_top"`). ↑ ↓ choose, Entrée opens (typed before the results came, it
  waits for them: « Ctrl K, ach, Entrée » opens the purchases' grid), Ctrl Entrée opens in a new tab,
  Échap closes and gives the focus back - a screen that redrew the element left is sent
  `px:focus-return` on the element's controller (the entry grid refocuses its cell). A line that is
  also a rail entry shows its Alt key, read from the rail. The teacher space has no palette yet (2.9).
- **`GlobalSearch::search()`** never leaves the dossier: empty field → the recent screens
  (`RecentScreens`, session, per dossier, five, completed by `SearchScreens::FIRST_SCREENS`); else, in
  this order, Écrans (`SearchScreens::screens()`, one line per journal's grid, the guided entry when
  `Assistance::opensGuidedEntry()`), Comptes and Tiers (F4's rules, `AccountSearch`/`ThirdPartySearch`, then
  ordered by `SearchRanking`, with their balance), Écritures (`EntrySearch::first()` by `EntryNumber`
  « ACH-2026-00012 », by document start, by a line's exact amount, over every exercise), Actions
  (`SearchScreens::actions()`, for EDIT only). An account or third party opens its consultation, an
  entry its fiche, both with `return` = `here` (`ReturnPath::of()`).
- **`SearchRanking::rank()`** (pure): a code typed whole, a code's start, the label's first word, a
  word's start (label or `terms`), a word merely held; null when it does not answer.
- **`SearchScreens` is the catalogue**: every screen a dossier gains is added there (route in
  `SCREEN_ROUTES`, label, path as `detail`, pictogram, `terms`); its key is the route
  (`app_accounting_entry:ACH` for a journal's grid), what `RecentScreensListener` records on a whole
  page (GET, 200, HTML, not a Turbo Frame) of a catalogue route. Pictograms named in PHP are checked by
  `GlobalSearchTest`, not `IconNamesTest`.

## Lettering and opening balance (built at step 1.6)

Lot 1, step 1.6 (design-ux.md §§ 3.1 gabarit X, 3.2, 5.3, 6.3), held by code in
`src/Service/Accounting/Lettering/`, `src/Service/Accounting/Opening/`, `LetteringController`,
`OpeningController` and `lettering_controller.js`:

- **A line is lettered by its letter and its date** (`EntryLine::$letter`, `$letteredOn` = the
  dossier's date, the FEC's EcritureLet/DateLet), both in `MUTABLE_WHEN_VALIDATED`. **Only a
  validated entry's line is lettered**: `LedgerGuard` refuses a letter on a draft's line (a draft is
  rewritten whole). **`Lettering` is the one door** (`LetteringDoorTest`: nothing else calls
  `letteringSet()`/`letteringClear()`): `letter()`, `unletter()`, `proposals()` and
  `letterProposals()`, each under a pessimistic lock on the account's row, the account's lines read
  again under it (`EntryLineRepository::findOnAccount(…, refresh: true)` - it never fetch-joins the
  entries then: refreshing an entry drops the lines it holds), the audit trail written
  (`LinesLettered`, `LinesUnlettered`), flushed in one transaction; a refusal is `LetteringRefused`.
- **A lettering belongs to a `LetteringKey`**: a lettrable general account whole, or a third party on
  a collective (401SCIE on 401000; Girard on 419100 is another key, `?on=419100` on the screen).
  Never across two keys, nor in a closed exercise.
- **Letters** (`LetterSequence`, pure): A…Z, AA… up to four, the next after the highest in use on the
  key; capitals for a group that balances, small letters for a partial one (Sage). Compare with
  `LetterSequence::same()` - MariaDB's collation ignores the case anyway, so never ask SQL to tell
  « a » from « A ».
- **`LetteringRules::decide()`** (pure): a total letter refuses the line, a partial one brings its
  whole group (completed under its letter, the smallest when two merge), debits and credits both,
  balanced or partial - partial only where `AssistanceLevel::blocks()` is false (A, 0).
  **`LetteringProposals`** (pure): by amount (equal amounts, oldest with oldest) and by document
  (same pièce, balanced). Délettrer refuses a group with a line in a closed period.
- **Screens**: Comptabilité › Consultation › Lettrage (`/d/{dossier}/accounting/lettering`): without
  an account, the keys with open lines (`LetteringBoard::rows()`); with `?account=401SCIE`, the
  face-à-face (`LetteringBoard::view()`, the template's variable is `face`: **the dossier shell
  already holds a `view`**), `all=1` shows the lettered lines, `auto=amount|document` previews the
  automatic lettering (`none` closes it; by amount by default at level G), `last=A` offers Ctrl Z.
  Espace ticks (rows_controller.js), Tab changes side (boxes and buttons are `tabindex="-1"`),
  Suppr délettre, Ctrl Entrée letters; the selection's sum is the browser's, the server checks all.
  POST `…/letter`, `…/unletter`, `…/auto` (CSRF `lettering-<dossier>`, EDIT). The consultation
  offers « Lettrer ». An account whose lines carry letters stays lettrable (`AccountSheet`,
  `ChartImport`).
- **À-nouveaux**: an entry of the opening journal is dated the first day of its exercise
  (`EntryRules`). The opening balance is typed in that journal's grid; Comptabilité › Saisie ›
  À-nouveaux (`/d/{dossier}/accounting/opening[?exercise=2026]`, `OpeningBalance::of()`) reads it by
  account and checks it: balanced - the new groups of the journal's pending work in the exercise's
  first month included, the one place it can be unbalanced -, validated, classes 1 to 5 only. Saisie
  always draws its tabs now (Par journal, Guidée when open, À-nouveaux).
- `fixtures/demo/letterings.yaml` (`DemoLetteringFile`): automatic letterings by amount, installed
  after the subscriptions (F0001, F0009, F0018). The copier (1.8) copies `letter` and `letteredOn`.

## Profiles and the dossier's trail (built at step 1.7)

Lot 1, step 1.7 (conception § 4; design-ux.md §§ 3.2 and 6.1), held by code in
`src/Service/Dossier/Rights/`, `App\Security\Voter\DossierRightVoter`, `App\Service\Audit\` and
`App\Controller\Dossier\{UsersController,AuditTrailController}`:

- **A member of a group's dossier has a profile** (`DossierMember::$profile`, `DossierProfile`:
  comptable fournisseurs, comptable clients, gestionnaire de paie, chef comptable; null = every
  right). **`ProfileMatrix::level()` is the one place the rights are written** (pure): rows are
  `DossierArea`s - the journals by type (`ofJournal()`: Achats, Ventes, Banque et caisse, Opérations
  diverses et à-nouveaux) and « Paramètres du dossier » (fiche société, plan, journaux, tiers,
  modèles, abonnements) -, levels are `AccessLevel` Closed < Read < Enter < Validate (Settings tops at
  Enter, « Modification »). A lettering is in its key's area (`ofLettering()`: a supplier with the
  purchases, a customer with the sales, else the OD). Readings (consultation, editions, brouillard
  list) stay open to every profile. A later module adds its area and its row: the `match` refuses a
  forgotten case.
- **`DossierRights`** reads a person's profile (`profileOf()`, memoised per request, reset in the
  worker) and words a refusal as design-ux § 6.1 does (« Votre profil “Comptable fournisseurs” ne
  permet pas de valider le journal de banque. », `Gesture`). Anybody who is not a group's member - the
  student of an individual dossier, a teacher, a command - has every right; DossierVoter still decides
  who enters.
- **The doors hold it**: `Ledger` (draft, update - old and new journal -, discard: Enter; validate,
  reverse: Validate; refusal under `journal`, or `entry.<id>` for a validation) and `Lettering`
  (Enter on the key's area) ask `DossierRights` whenever the actor is a `User`. **The screens ask
  `DossierRightVoter`**: `DOSSIER_SETTINGS` (a dossier: every settings screen and post, Alt Inser in
  the grid), `JOURNAL_READ` (a closed journal is not offered in the grid, Ctrl K, the guided entry;
  asking it opens the first one allowed), `JOURNAL_ENTER` (the grid's fields and posts, F6, the
  à-nouveaux), `JOURNAL_VALIDATE` (Valider / Ctrl Entrée, the entry's validation and reversal). The
  brouillard holds back what the person may not validate (`BrouillardRow::$refused`, « Hors de votre
  profil »). A grid the profile reads or does not validate says why above it.
- **Utilisateurs et droits** (`/d/{dossier}/users`, Système d'information): who enters and how far
  (`DossierUsers`: the student or the members with their profiles, the teachers of their classes
  this year, the distributor; a template's author and shares), then the matrix. In a group's dossier
  a teacher of the class (`DossierVoter::SET_DATE`) gives the profiles with F10 (`DossierProfiles`,
  one line of the trail per change). The member's profile is the violet chip of the top bar.
- **The dossier's trail**: `AuditTrail::inDossier(dossier, action, actor, summary, details)` stamps
  `dossier_id` (no foreign key: the trail outlives the dossier, and the copier of 1.8 never copies it),
  `dossier_date` (the dossier's date at the gesture), `actor_role` (teacher / student; null for a
  command) and `actor_profile`; `record()` throws on the subject « dossier ». **Piste d'audit**
  (`/d/{dossier}/audit`, read by whoever opens the dossier): newest first, the real time - the one
  place of the PGI it shows - and the dossier's date, the author (« Enseignant » chip, or the
  profile), the gesture, its sentence; chips by role, a gesture, words; `…/audit/export.{csv,xlsx}`
  (`DossierTrailExport`). Lines written before 1.7 have their dossier (migration) but no date.
- Both screens are in Ctrl K (`SearchScreens`), under the module tabs `dossier/_si_tabs.html.twig`.

## Templates, library and the dossier copier (built at step 1.8a)

Lot 1, step 1.8a (conception § 4; design-ux.md §§ 2.6, 3.3, 6.2), held by code in
`src/Service/Dossier/Copy/`, `src/Service/Dossier/Library/`, `App\Security\Voter\TemplateVoter`,
`src/Controller/Library/` and `templates/library/`:

- **`DossierCopier::copy(from, to, CopyKind)` is the one deep copy** (README of the plan, § 8):
  duplication now, then restore points (1.8b) and the distribution (1.8c). It copies into a dossier
  that holds nothing yet every table `CopyPlan` reads from the Doctrine mapping of the DossierOwned
  entities, each after the tables it points at, plus the company's fiche and the chart code
  (`Dossier::takeContentOf()`) and the files (`DossierFiles::copyAll()`). One `INSERT … SELECT` per
  table in the source's id order, so a copied row keeps its rank: a reference into the dossier is
  rewritten through a temporary correspondence table (joined several times in one statement:
  MariaDB allows it, MySQL does not); a reference into its own table (`entry.reversal_of_id`) once
  every row is in; a column marked `#[DossierFileKey]` with its file's new key; a JSON column through
  its `ColumnRemapper` (`PendingEntryGroupsRemapper`: the grid's pending work names the drafts it
  rewrites). A reference outside the dossier (a person) is kept. DBAL, around the ORM and its
  guards: a copy reproduces, it changes nothing. A shape it does not know (a many-to-many, a second
  reference to a dossier, an id that is not a generated `id`) stops `CopyPlan`.
- **What a copy carries**: `CopyKind::Template` (duplicated or handed out) leaves a person's pending
  work behind (`CopyPlan::PENDING_WORK`), `CopyKind::Snapshot` (a restore point and the way back)
  keeps it. `CopyPlan::NOT_COPIED` names what no copy carries, with the reason: `DossierDateChange`
  (the date's history stays with the dossier). Members, shares and the audit trail are global: never
  copied.
- **`DossierEraser`**: `eraseContent()` empties a dossier of what a copy brings back (rows and files;
  its row, members, shares, date history stay) - the way back from a restore point; `deleteDossier()`
  deletes it whole, the trail kept. Both DBAL, in the reverse order, self-references set to null
  first. Entities of the dossier loaded before are stale afterwards: clear the entity manager in a
  command that goes on. `DossierCopierDoorTest`: nothing else calls `copyAll()` / `deleteAll()`.
- **The completeness test** (`DossierCopierTest`, `tests/Support/CanonicalDossierExport`): the
  demonstration plus what it lacks (a pending entry rewriting a draft, a reversal, a moved date, a
  file), copied, both exports compared - a reference written « table#rank ». It fails when a
  DossierOwned table holds no row in the source (add some to the demonstration or the test), when a
  copy differs, and when a column holds a file's key without `#[DossierFileKey]`.
- **The library** (Bibliothèque, rail entry `book-copy`; `/teacher/library`): « Dossiers modèles »,
  cards filtered by shelf (`TemplateShelf`: mine, shared with me, provided = the common library) and
  searched (`TemplateLibrary::cards()`, a fixed number of queries; uses = students' and groups'
  dossiers made from it), and « Partages » (given and received). A template's card is on `Dossier`
  (`libraryTitle` - the company's name when empty -, `libraryDescription`, `libraryLevel`
  (`TemplateLevel`), `libraryProcesses` (`App\Enum\Shared\BtsProcess`, P1 to P7, official
  wording)), changed through `TemplateCards` (DossierVoter::EDIT, a line of the trail).
- **Gestures**: « Nouveau dossier modèle » (gabarit E): an empty one (`TemplateFactory`: the fiche
  société - `dossier/_company_fields.html.twig`, `CompanyDraft::fromRequest()` -, the first exercise,
  the dossier's date, the reference chart, and five journals ACH, VTE, BQ on 512000, OD, AN), or a
  copy; « Dupliquer » (`TemplateDuplicator`: the author's own, `CopyKind::Template`, « (copie) »,
  origin = the template, no share); shares (`TemplateShares`: read or change, changed, taken back;
  colleague picked by `user_picker_controller.js` on `…/colleagues`); « Supprimer » (`TemplateDeletion`:
  a récapitulatif, then the title typed; the copies keep their content and lose the link).
  `TemplateVoter`: SHARE the author alone (nobody for the common library), DELETE the author or, for
  the common library, an administrator, DUPLICATE whoever reads it. `/teacher/library/{dossier}` of a
  dossier that is not a template answers 404.
- A template's ribbon (gold) goes back to its fiche in the library. The demonstration's card is in
  `fixtures/demo/company.yaml` (`library:`).

## Restore points (built at step 1.8b)

Lot 1, step 1.8b (design-ux.md § 4.9), held by code in `src/Service/Dossier/Restore/`,
`RestorePointsController`, `RestoringController` and `RestorationInProgressListener`:

- **`RestorePoint`** (global): the dossier, its frozen copy (a `Dossier` of kind Snapshot), name,
  author, real time, dossier's date, origin (manual / before a return), state (preparing, ready,
  failed). **`DossierRestoration`** (global): a return, running / done / failed. Ten points a dossier
  (`RestorePointRules`). Rights: `RestorePointVoter` - POSE with `DossierVoter::EDIT`, RETURN and
  DELETE with `DossierRightVoter::SETTINGS` too.
- **`RestorePoints` and `Restorations` are the doors**; the copy runs after the answer
  (`RestoreQueue`, `RestoreWorkListener` on kernel.terminate), under the dossier's lock
  (`RestoreRunner::lockName()`, the distribution will take it too), and
  `app:restore-points:advance` finishes what a stopped worker left. A return erases the rows and
  copies the point's back **in one transaction**; the files are copied in beside, and the ones no row
  names any more deleted after it holds (`DossierFiles::keys()`, `deleteKeys()`). The dossier's date
  never moves; the trail gains a line. After a return the entity manager is cleared.
- **While a return runs**, every GET of the dossier is redirected to `/d/{id}/restoring` and every
  other request answers 409. The shell subscribes to the dossier's private topic
  (`DossierLive::topic()`, cookie of the `subscriber` hub set by `dossier_live_url()`): `restoring`
  sends every tab to the waiting page, the others reach the screen as a `peyra:live` event
  (`live_frame_controller.js` redraws a frame; it also polls while a copy is under way).
- `DossierEraser::deleteDossier()` deletes a dossier's points (and their copies) first. Locks are
  in memory in the test environment (ParaTest processes share the project directory, not their
  ids). Twig resolves `point.ready` to a method `ready()` before `isReady()`: mutators are `mark…()`.

## Distribution (built at step 1.8c)

Lot 1, step 1.8c (design-ux.md § 4.2), held by code in `src/Service/Dossier/Distribution/`,
`App\Controller\Library\{DistributeController,DistributionsController}` and `templates/library/distribut*`:

- **`Distribution`** (global: template, title, author - null for a command -, class, mode, start date)
  and **`DistributionCopy`** (global: a student, or a group's name and members `{student, profile}`
  as JSON; its dossier once made; waiting / copying / ready / failed). `DistributionVoter::MANAGE`: its
  author alone, no administrator bypass; `TemplateVoter::DISTRIBUTE`: whoever reads the template.
- **« Distribuer »** (card and fiche of a template, `/teacher/library/{dossier}/distribute`): three GET
  steps carrying `DistributionChoices` (`class`, `pick[]`, `mode`, `group[id]`, `profile[id]`, `start`),
  then the POST of the récapitulatif. **`DistributionPlan::of()` is the pure rule**: on the first
  display everybody but those who already hold a copy of the template is ticked; default groups of
  about three as even as can be; a group of one, nobody ticked, a bad date stop it.
- **`Distributions` is the one door** (launch, make, fail, retry, moveDate, withdraw; audit trail
  subject `distribution`). **A dossier is made whole or not at all**, in one transaction: its row,
  `DossierCopier` (`CopyKind::Template`), a group's `DossierMember`s with their profiles, and its
  **« État de départ »** - a `RestorePoint` of origin `Start`, outside the limit of ten
  (`RestorePointRepository::countAgainstLimit()`), deleted by a teacher only; the files of a copy that
  stops go through `DossierEraser::deleteFilesOf()`.
- **Background**: `DistributionQueue` → `DistributionWorkListener` (kernel.terminate) → `DistributionRunner`,
  one copy per pass under the lock `Distributions::lockName($copyId)`, read again under it; a failure is
  logged, said on the line (« Relancer »), and the other copies go on. `app:distribution:advance` finishes
  what a stopped worker left. The progress page (`/teacher/library/distributions/{id}`) listens to
  `DistributionLive::topic()` (`distribution_live_url()`) and polls every two seconds while copies are pending.
- Moving the date of a distribution goes through `DossierDates::move()` dossier by dossier, for those the
  teacher may date (`DossierVoter::SET_DATE`: a teacher of the class this year); withdrawing deletes each
  dossier through `DossierEraser::deleteDossier()` (the copies detached first: the unit of work must not
  hold a dossier it deletes), after the title is typed.

## Classe › Dossiers and the reset (built at step 1.8d)

- **`/teacher/classes/{id}/dossiers`** (`ClassDossiersController`, tab of a class next to « Étudiants »,
  `classes/_class_tabs.html.twig`): `ClassDossiers::rows()` - the class year's dossiers of its enrolled
  students, groups included, a fixed number of queries -, each line filtered by `DossierVoter::VIEW`, ticked
  where `RestorePointVoter::POSE` allows. **`RestoreBatches`** is the gesture on several dossiers through the
  one-dossier doors: `pose()` (one name, a full dossier counted) and `resetToStart()` (`Restorations::returnTo()`
  to the ready `RestorePointOrigin::Start` point, `RestorePointRepository::findStart()`; the safety point only
  where ten points leave room); a refusal never stops the others (`RestoreBatchOutcome`). The récapitulatif is
  `dossier/_start_reset.html.twig`, shared with the distribution's « Remettre à l'état de départ »
  (`/teacher/library/distributions/{id}/reset`, its ready dossiers the author may EDIT).

## FEC (built at step 1.9a)

Lot 1, step 1.9a (A47 A-1 du LPF, read on Légifrance on 2026-11-28), held by code in
`src/Service/Accounting/Fec/` and `App\Controller\Closing\FecController`:

- **Immobilisations et clôture › Clôture › FEC** (`/d/{dossier}/closing/fec[?exercise=2026]`, rail entry
  `closing`, `templates/closing/_layout.html.twig` - lot 3 adds its other tabs there), and
  `…/download`: a reading, open to whoever enters the dossier.
- **`FecExport::of(dossier, exercise)`**: the exercise's validated entries only (the brouillard is
  counted apart), the à-nouveaux first then by `validatedOn`, entry date, journal, number; UTF-8
  without BOM, tab, CRLF (`FecWriter`); `EcritureNum` is `getNumberLabel()`, `PieceDate` the entry's
  date until lot 2's documents carry theirs; named `SirenFECAAAAMMJJ.txt` (`FecFormat`).
- **`FecConformity::check(bytes, name, start, end)`** is pure and checks the bytes handed over: name,
  header (or Montant/Sens), zones, required zones, formats, paired zones, entries contiguous, one
  day, balanced, in the exercise, numbering without a gap per journal, chronological order of
  validation after the leading reprise. **`FecReader`** (tab or `|`, UTF-8 or ISO 8859-15, BOM
  dropped) is the reader the FEC import starts from.
- **Import into a new template** (step 1.9b, Bibliothèque › Nouveau dossier modèle › Depuis un FEC,
  `/teacher/library/new/fec`): `FecImport` checks the fields (`TemplateFactory::prepare()`), the
  file (`FecConformity::check()` without name nor sequence), reads it with the pure
  `FecImportReading` (rules in its docblock) and **rehearses the doors before writing anything**
  (`FecImportRehearsal`: ThirdPartyRules and EntryRules over the template built in memory), each
  fault on its line. Then (step 1.10b) `launch()` opens the template **hidden**
  (`Dossier::$importing`: DossierVoter refuses it, the library leaves it out) and records a
  `FecImportJob` (global, the file gzipped); the writing is **in the background**, `pass()` by
  `pass()` - set-up, 250 drafts, 250 validations, 100 letterings -, each one transaction under the
  job's lock (`FecImportRunner`), through the usual doors, the entity manager **cleared** between
  batches (entities loaded before are detached after). Passes run after the answer for 12 s at
  most (`FecImportWorkListener`: **PHP's 30 s `max_execution_time` counts kernel.terminate too**),
  again at each poll of the progress page (`/teacher/library/imports/{id}`, Mercure + polling), and
  by `app:fec-import:advance`. A pass that fails stops the import: « Relancer » or « Abandonner »
  (the hidden template deleted, the teacher's gesture). 10 000 entries at most (`MAX_ENTRIES`). The
  demonstration's FEC imported then exported again gives back the same bytes (`FecImportTest`).

## Pièces (built at step 2.1a)

- **`Document`** (DossierOwned, `src/Entity/Document/`): nature (`DocumentType`), state (`DocumentState`:
  à traiter, en cours, comptabilisée, écartée), its file (`#[DossierFileKey]`, category `pieces`, PDF or
  picture: `Documents::policy()`), the dossier's date it arrived on, and its **read data** - third party,
  number, dates, order reference, VAT by rate (JSON of `VatLine`), three totals - **read, never
  computed**: `DocumentReadRules` (pure) checks their shape only, a faulty pièce keeps what it prints.
  `Documents` is the one door (deposit, read data; trail). Its entries are read, not linked: those whose
  pièce reference is its number (`EntryRepository::findByDocumentReference()`).
- Screens: Pièces › Boîte de réception (`/d/{dossier}/documents`, the rail's counter is
  `DossierShellView::$documentsToProcess`), `…/new`, the sheet `…/{id}` (`Px:Viewer`: a picture zoomed
  by the page, a PDF by the browser's reader in a frame; F7 lays it over the window), `…/{id}/file`
  (the private storage's one door, inline or `?download=1`). Ctrl K's group « Pièces ».
- **The generator** (step 2.1b, `src/Service/Document/Generator/`, teacher rail « Pièces »,
  `/teacher/documents/generator/{template}`, a template the teacher may EDIT): a purchase invoice
  described in data, checked by `InvoiceRules` and computed by `InvoiceCalculator` (both pure: VAT
  once per rate on its bases; the faults are digit swaps), printed in its supplier's look
  (`InvoiceCharter`: one of three `templates/document/invoice/` layouts, read from the code), then
  `Documents::generated()` - read data = what it prints. `Document::$generation` keeps the operation
  and its faults: answer key, no student screen shows it. The form is a GET (the preview's address);
  « Générer » posts, its token as the button's value.
- **Processing a pièce** (step 2.2a, `src/Service/Document/Processing/`, `PieceProcessing` the one
  door, module `AccountingModule::Documents`): the proposal is **kept on the pièce**
  (`Document::$proposal`, `PieceProposal`: GridLine-shaped lines + the fingerprint of the read data
  it came from), made by the pure `PieceProposals` when the pièce first has read data (arrival of a
  generated one, F10 on a deposit) and again only by « Reprendre l'écriture depuis les données lues »;
  the expense/revenue account is the one the third party's latest entry used
  (`EntryLineRepository::usualAccountOf()`), else left empty. The entry is checked by
  `JournalGrid::check()` (the grid's own rules and controls) and written through the gateway;
  `Document::$entry` links the pièce to it. `PieceControls` (pure) checks the read data by level.

## Référentiel and assistance levels (built at step 2.8)

- **The référentiel is versioned data**, `config/referential/bts-cg.yaml` (edition 2014: four exams,
  seven processes, 37 activities, 115 components, official wordings), read and checked whole by
  `App\Service\Shared\Referential\Referential`; a mission names an activity by its code (`A1.2`).
  `BtsProcess::label()` must stay equal to the file (`ReferentialTest`).
- **`App\Service\Shared\Assistance\Assistance` is the only place a level means something**
  (`Assistance::at($levels->for($dossier))`: runs controls, blocks, partial or proposed lettering,
  guided entry, account outside the chart). Never compare an `AssistanceLevel` case elsewhere:
  `AssistanceDecidesTest` refuses it. A new behaviour by level is a method there, with its row in
  `AssistanceTest`.
- **The level a dossier is worked at** is `StudentAssistanceLevels` (the `AssistanceLevels`
  service): the student's own level (`User::$assistanceLevel`, Fiche étudiant › Aménagements,
  `StudentAssistance` the one door, administration journal) - the owner's for a student's dossier,
  the working member's for a group's - else « Avertissements ». The missions (2.9) slot theirs in
  between.

## Payroll settings (built at step 4.1)

- **Paie et social › Paramètres de paie** (`/d/{dossier}/payroll/items|bodies|establishment`,
  `App\Controller\Payroll\*`): the plan de paie (`PayrollItem`, « rubriques »: family, base,
  ceiling, a `PayrollRate` per side, conditions on status, headcount and bracket, the body and the
  `PayslipLine` of the clarified payslip a contribution is summed under), the collecting bodies
  (`PayrollBody`) and the establishment (`PayrollEstablishment`: headcount, work accident and
  mobility rates, working time). `PayrollItemRules` is pure; `PayrollItemSheet` adds the code, the
  body and **a regulatory value that reads at the dossier's date** - a rate the law fixes is never
  written in a rubrique, it names its key and path (`social.agirc_arrco.ceg`, `0.employee_rate`).
- **The standard plan is data**, `fixtures/payroll/standard-plan.yaml` (58 rubriques), installed by
  `PayrollPlanInstaller` into an empty plan; `StandardPayrollPlanTest` reads every value it names
  from `config/regulatory/2025.yaml` and `2026.yaml`. 2025's maladie and famille carry their full
  rate under `employer_rate`, like 2026, the reduced one beside it for the engine.
- **The area « Paie et social »** of `ProfileMatrix`: the payroll manager's and the chief
  accountant's, closed to the two accountants; screens ask `DossierRightVoter::PAYROLL_READ` (the
  rail hides the entry) and `PAYROLL_SETTINGS`.

## Employees (built at step 4.2a)

- **Paie et social › Salariés** (`/d/{dossier}/payroll/employees[/{matricule}]`, the rail's entry):
  `Employee` (civil status, NIR, contract type and dates, withholding rate; null = neutral rate) and
  **`EmploymentTerms`, dated**: job, classification, status, working time, monthly hours, base
  salary from a day on. The first terms start on the day of hire and follow it; each change is a
  row from its own day (UNIQUE employee + day), and the rows are the history. **Read the terms in
  force with `Employee::termsAt($day)`**, never the latest row; the hourly rate is read (salary ÷
  hours), never stored. Full time works the establishment's collective hours.
- `EmployeeRules` is pure; `EmployeeSheet` the one door (fiche, demonstration), audit trail
  included; « À compter du » = the day shown corrects, another day adds a row. A NIR's format and
  its agreement with sex and birth are checked, **never its key** (fictitious, key « 00 »).
  Writes ask `DossierRightVoter::PAYROLL_ENTER`. `fixtures/demo/employees.yaml`: S001-S011.

## Hiring formalities and the social portal (built at step 4.2b)

- **Salariés › {matricule} › Formalités** (`EmployeeFormalitiesController`): the DPAE, the contract
  PDF, the information and prevention visit's day, one affiliation per `PayrollBody`; **Salariés ›
  Registre unique du personnel** (`PersonnelRegister`, read from the fiches, screen and PDF).
  `HiringFormalities` is the one door (audit trail); `HiringChecklist` (pure) says where each stands -
  an employee hired before the dossier's first exercise is « Avant le dossier », not followed.
- **The simulated social portal** (`/d/{dossier}/payroll/social-portal/dpae/{matricule}`): the DPAE
  read from the fiche, checked by the pure `DpaeControls`, rejected with `PortalFault`s (code,
  motive, explanation at level G: `Assistance::explainsPortalRejections()`) and nothing kept, or
  accepted as a `HiringDeclaration` (frozen content, number, late or not) with the Urssaf
  affiliation that day. The grey frame is `templates/portal/_frame.html.twig` (+ `portal.css`),
  to `embed`: the tax portal and the DSN reuse it. The delays are regulatory values
  (`social.hiring.*`, `HiringDelays`): **installing the demonstration needs `app:regulatory:sync`
  first**, and a test that installs it syncs.
- At G and B the fiche requires the DPAE's fields (`Assistance::requiresHiringDeclarationFields()`:
  birth place, hour of hire).

## End of contract (built at step 4.2c)

- **Salariés › {matricule} › Fin de contrat** (`EmployeeExitController`): the exit on `Employee`
  (`ExitReason`, notification day, last day), checked by the pure `ExitRules` (which reason ends
  which contract, a term on its day without notification) and written by `EmployeeExits` alone
  (audit trail); cancelled in two steps. **Present on the last day, `Departed` the next**
  (`stateAt()`); the register writes the day once it has come. `EmployeeSheet` re-reads the exit
  against a changed contract and refuses terms after it. The work certificate is a PDF
  (`JobPeriods`: same job merged). The reçu pour solde de tout compte and the France Travail
  attestation read payslips: steps 4.4 and 4.6.

## Payslip engine (built from step 4.3a)

- **`PayslipCalculator::calculate(PayslipInput): Payslip` is a pure function** (`src/Service/Payroll/Engine/`):
  the employee on the day (`PayslipEmployee::at()`), the month's elements by rubrique code
  (`PayslipVariables`, `PaidLeave`), the `RegulatorySnapshot` of the payslip's day and the plan's
  `PayrollItemSpec`s. No database, no clock, no rate in the code: a rubrique says its base and the
  source of its rate. Every `PayslipRow` carries its `PayslipExplanation` (formula, origin of the
  base and of the rate, regulatory `PayslipSource`).
- **A line's amount is its printed base × its printed rate, half up to the cent**; the hourly rate
  is the contract's at four decimals, a premium applied to it then rounded to four. The mensualised
  salary is the exception: its amount is the contract's salary. Tests: `tests/Service/Payroll/Engine/`,
  reference payslips worked out by hand (`StandardPlanFixture` reads the plan and the millésimes).
- **Contributions** (step 4.3b, `PayslipContributions`): `PayslipInput` also takes the
  `PayslipEstablishment` (headcount, AT and mobility rates, collective hours). **Each share is rounded
  on its own, rubrique by rubrique**; a line of the clarified payslip is their sum
  (`Payslip::lineTotal()`), never base × summed rate. Brackets, the CSG abatement and the reduction's
  parameters are numbers in `config/regulatory/` (`social.ceiling_brackets`, `social.csg_abatement`);
  a rate whose value carries `<rate>_reduced` + `reduced_rate_threshold_smic` is 2025's reduced rate.
- **The general reduction has one formula per millésime**, chosen by `social.general_reduction.regime`
  (`rgcp` → `GeneralReductionRgcp`, `rgdu` → `GeneralReductionRgdu`): a new millésime's formula is a
  new class and a new regime value, never an `if` on the year. Monthly, against the January SMIC of
  reference (`smic_monthly_reference`) prorated and raised by the overtime hours; `DecimalPower` gives
  the RGDU's ^1,75 without a float.
- **Net and year to date** (step 4.3c, `PayslipNet`, `PayslipTotals`, `WithholdingTax`): `PayslipInput`
  takes the year **before** the payslip (`PayslipYearToDate`: gross, ceilings, SMIC of reference,
  reduction made, exempt overtime, net taxable - the caller sums the year's earlier payslips) and
  `Payslip::$yearToDate` gives it back **after**. With it, brackets and the general reduction are
  regularised over the year (a month's tranche 2 or reduction may be negative). Net social excludes
  the employer's health and provident shares (R844-1 II, since 2024); net taxable adds the employer's
  **health** share only. A gross rubrique `benefit_in_kind` is taken back off the net by 4000.
  Reference payslips: `tests/Service/Payroll/Engine/reference/bulletins_4_3c.py`, a decimal script
  that never reads the repository - extend it, never copy an expected amount from the engine.
- **An incomplete month** (step 4.3d, `PayslipMonth`, `PayslipPresence`): the month is the payslip's
  day's; `PayslipEmployee` carries the hiring and last days, `PayslipVariables::$unpaidDays` the whole
  days paid nothing. The ceiling is prorated on calendar days, the SMIC of reference corrected by salary
  due ÷ whole-month salary. **Absences are recognised by their base, never their code**: the salary is
  `contract_hours`, an absence `entry_exit_days` or subtracting `entered_hours` at the hourly rate, the
  sick pay `sick_pay` (hours as quantity, daily allowances as amount, on the same code).
- **The apprentice and the end of a contract** (step 4.3e): `PayslipApprentice` (regime by the contract's
  start, `social.apprentice.exemption`) shrinks the employee's base of the rubriques with a regulatory employee
  rate - `PayslipRow::$employeeBase`, the employer's base unchanged - and the CSG base; `PayslipNet` exempts the
  net taxable within the annual SMIC (`PayslipYearToDate::$apprenticeExempt`). The fixed-term indemnity (1500)
  and the compensatory leave (1320) are computed after the month's gross; `PayslipSeverance` is the one reading
  of a dismissal or conventional termination indemnity and **refuses** one partly subject to contributions.

## Cross-cutting building blocks (built at step 0.6a)

Reach for these rather than re-implement them; each has its test.

- **Money**: `App\Service\Shared\Money` (whole cents, brick/math, an overflow throws), column
  `#[ORM\Column(type: 'money')]` (BIGINT, `App\Doctrine\Type\MoneyType`). `times()` takes the
  rounding as an argument: there is no default rounding. JSON carries cents as an integer.
  `AmountParser::parse()` reads what a person types (« 1 234,56 », « 1234.56 », « −138 », thousands
  « 1.234,56 ») or throws `AmountParseError` with the sentence to print; it never computes.
- **French formats**: `FrenchFormat` behind Twig filters (`App\Twig\FormatExtension`): `amount`,
  `amount_cell` (empty for zero), `amount_eur`, `balance` (« D »/« C »), `whole_euros`, `percent`,
  `quantity`, `hours`, `date_fr`, `day_month(year)`, `date_chip`, `period`, `clock`, `duration`.
  Thousands are U+202F, a unit follows U+00A0, a minus is U+2212. No ICU, no locale, no clock.
- **Fields**: `Px:AmountInput` (display field + hidden cents, arithmetic on leaving the field) and
  `Px:DateInput` (« JJ/MM/AAAA » + hidden ISO day; `reference` is the dossier's date, never the
  computer's: no `<input type="date">` nor `type="number"`, `NoNativeDateOrNumberFieldTest`). The
  server reads `DateValue::parse()`.
- **Readers**: `QueryValue`, `PostValue`, `FormValue` (+ `money()`), `JsonRequestPayload`
  (+ `date()`; no float), `ListPage::fromRequest($request, 'screen.name')` (25/50/100, 50 by
  default, remembered per screen in the session) with `<twig:Px:Pager … :sizes="true"/>`.
- **Files**: `App\Service\Shared\Storage\DossierFiles` is the only writer of the documents
  storage: keys `dossiers/<id>/<category>/<random>.<ext>`, every reading takes the dossier and
  refuses a key outside its prefix, `copyAll()` copies a dossier's prefix and answers the key map.
  `documents.storage` is `documents.local` (`var/storage`, the `app_storage` volume shared by `php`
  and `worker`; under the cache in test, one directory per ParaTest process - `storage<TEST_TOKEN>` -
  since each process numbers its dossiers in its own database) or `documents.s3`, by `PEYRA_STORAGE`. `UploadPolicy`:
  one platform rule (20 Mo, pdf/images/csv/txt/ofx/xml/xlsx/ods), narrowed per field with
  `restrictTo()` / `limitedTo()`, never widened; the type is sniffed from the bytes.
  **On S3, Peyra sends no ACL**: Flysystem puts `private` on every write and copy, which would need
  `s3:PutObjectAcl` and which a bucket with ACLs disabled refuses - `S3ClientFactory` strips it
  (handler step `peyra.no_acl`), and `documents.s3` has `retain_visibility: false` (else a copy reads
  the source's ACL). `S3BucketPolicyTest` plays every public method of `DossierFiles` against a
  stubbed S3 and requires `infra/terraform/iam.tf` to grant exactly the IAM actions of what is sent:
  a new gesture or a new S3 operation fails it until the policy follows. `deleteAll()` (step 1.8a)
  is what uses `s3:DeleteObject` (`DeleteObjects` in one request).
- **PDF**: `App\Service\Shared\Pdf\PdfRenderer` (`renderTemplate`, `render`, `merge`,
  `pageCount`) is the one door to Gotenberg. A document extends `doc/_document.html.twig` (a state
  Peyra edits: `doc/_report.html.twig`, header and foot in `@page` margin boxes) and links
  `assets/styles/doc/*` by their bare names, posted with the fonts: Gotenberg never reaches the
  application. `doc-fonts.css` is excluded from AssetMapper. Totals in a `tfoot` are a row group
  (a footer group would repeat them on every page). Sample: `/_design/document` and
  `/_design/document.pdf`; look at a PDF with `pdftoppm -png` on the host.
- **Live**: `LivePublisher::publish($topics, $data)` - private by default, a failure is a warning
  in the log and `false`, never an exception. Topics are `peyra/<area>/<id>`.
- **Scheduled commands**: `App\Command\Shared\SharedLockableTrait` (`$this->lock()`, released when
  `run()` returns); `App\Scheduler\ScheduledCommandLogger` writes one line per run in the
  worker's log, the output's tail at error level when it fails.
- **Background work** (step 1.10c): a request's kernel.terminate works while its
  `App\Service\Shared\Background\WorkBudget` lasts (12 s: PHP's 30 s counts it), the progress
  pages queue their work again when they poll, the scheduled commands finish the rest. A unit
  that copies or erases a dossier runs in `TransactionRetry::run()`: two dossiers copied at the
  same moment deadlock on the `dossier_id` indexes, and InnoDB's victim surfaces as « SAVEPOINT
  DOCTRINE_2 does not exist » with DBAL left counting a dead transaction - `recover()` closes
  that connection.

## Regulatory values and audit trail (built at step 0.6b)

- **`RegulatoryValue`** (`src/Entity/Regulatory/`) is one dated value of a key (`social.smic_hourly`,
  column `value_key`: `key` is reserved): label, value, unit, effective date, source, date of
  consultation, `verified`, note, origin (a millésime file or an administrator), `millesime`.
  **Append-only**: a new value is a new effective date; a correction is one more row at the same
  date, which replaces it for every reading (the last row written for a date wins) while the older
  one stays in the history (`RegulatoryHistory`, `RegulatoryVersion`).
- **The value is `RegulatoryTree`'s canonical JSON** - a number, a text, or a structure (map, list
  of brackets) of them - **numbers kept as exact decimal texts** (« 6.9 », « 0.3193 ») stored as JSON
  strings: decoding never yields a float. A leaf whose text is a plain decimal is a number; any
  other text is a text (a date is « 2027-01-01 »).
- **Reading**: `RegulatoryReader::at($dossierDate)` gives a `RegulatorySnapshot`, typed by key and
  path: `decimal()`, `rate()` (a value in percent as a fraction), `money()` (refuses more than two
  decimals), `integer()`, `text()`. Absent or of the wrong kind: `MissingRegulatoryValue`, never a
  default. No rate is written in the code (lots 3 and 4).
- **The millésime files** `config/regulatory/<année>.yaml` follow the prepared data's schema (plan,
  `donnees/reglementaire/LISEZ-MOI.md`); `RegulatoryFile` checks a file whole and names every fault.
  The social values of 2025 and 2026 are in (step 4.1, from the prepared data); the fiscal ones
  arrive at lot 3.
  `app:regulatory:sync` (`RegulatorySync`) only ever adds rows: what is missing, what a file changed
  (the older row kept), never over an administrator's value (reported « conservée »); a stable rule
  both files carry word for word is written once; one fault anywhere installs nothing.
- **The screens**: Paramètres › Paramètres réglementaires (`/teacher/regulatory`), read by every
  teacher - the rail's « Paramètres » is now everybody's, its administration tabs administrators'
  (`teacher/_settings_tabs.html.twig`). The list is a millésime (every version applying on a day of
  the year), filtered by domain and searched; the sheet shows every date and the corrections;
  « Nouvelle valeur datée » (`ROLE_ADMIN`, `RegulatoryValues` the one door) starts from the latest
  value and changes its leaves, never its shape.
- **`AppendOnly`** (`App\Entity\Shared\AppendOnly`) marks a row never changed nor deleted;
  `App\Doctrine\AppendOnlyGuard` refuses the whole flush that tries. The sign-in and administration
  journals, the regulatory values and the audit trail carry it.
- **The audit trail** (`AuditEvent`, written by `App\Service\Audit\AuditTrail::record()` alone,
  which stamps the real time itself - `src/Service/Audit/` is the one business place that reads the
  clock): who (an account, or a command's name), what (`AuditAction`, one case per gesture), on what
  (`subjectType` + `subjectId`), a French sentence and JSON details. The caller flushes with its
  gesture. A gesture in a dossier goes through `inDossier()` (step 1.7, « Profiles and the dossier's
  trail »).

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
  answers a 400 to the empty string a filter bar submits), `PostValue` (the fields of a plain
  posted form: ids, a table of choices by id or by key), `FormValue` (`getData()` is mixed),
  `DataTableParams` (paging, capped). Shapes are `@phpstan-type` on the producer,
  `@phpstan-import-type` where consumed.
- **Interactivity is Stimulus controllers** under `assets/controllers/`; never inline JS. A POST form
  handled by Turbo must redirect; a « show me a result » form uses `GET`.
- **Forms**: checkbox groups rather than `<select multiple>`; a select used for input has a
  placeholder; picking users uses an autocomplete fed by ajax.
- **Worker mode**: a service that keeps state between calls implements `ResetInterface`. A scheduled
  command that locks itself uses `App\Command\Shared\SharedLockableTrait`, never Symfony's
  `LockableTrait` (the worker reruns the same instance).
- **Publishing to Mercure is a comfort, never a condition**: always through `LivePublisher`, which
  catches and logs.
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
  then `doctrine:schema:validate`, the suite through ParaTest, then once more in one process. No repository secret. A push touching only `**.md`
  may skip it — on the `push` trigger only, never on `pull_request`. A newer push cancels a pull
  request's run, **never a push to `staging`** (a group per run): two tracks push more often than
  a run lasts.

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
