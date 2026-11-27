# Tests

```console
docker compose exec -e APP_ENV=test php bin/phpunit
docker compose exec -e APP_ENV=test php bin/phpunit --filter NoClockReadingTest
docker compose exec -e APP_ENV=test php vendor/bin/paratest --processes=4   # the whole suite, in parallel
```

The tree mirrors `src/` (`tests/Controller/Shared/` tests `src/Controller/Shared/`…), plus:

- **`tests/Architecture/`** — the invariants of the build plan (§ 8), each held by a test that reads
  the code or the ORM metadata rather than trusting discipline: no floating-point column
  (`NoFloatColumnTest`), no business calculation reading the server clock (`NoClockReadingTest`),
  no native date or number field (`NoNativeDateOrNumberFieldTest`), the colours and contrasts of
  the design system (`DesignTokensTest`), the production configuration
  (`ProductionConfigurationTest`); the list of scheduled tasks, none of which deletes, is pinned by
  `tests/Scheduler/PeyraScheduleTest`.
  Each also checks itself on a hand-written offender, so a test that finds nothing is known to be
  able to find something. More arrive with the lots (`DossierOwned`, the single door of the ledger…).
- **Unit tests** over pure services: no kernel, no database. A business rule is tested on figures
  written by hand in the test, computed apart, never copied from the code's output.
- **Voter tests**, one per Voter, through the public `vote()` so that `supports()` is exercised too.
- **Functional tests** (`WebTestCase`): real HTTP requests, among them the smoke test that asks for
  each main screen as a student, a teacher, an administrator and a refused account.

## Isolation and the directory

Every test runs inside a transaction rolled back at its end (`dama/doctrine-test-bundle`, wired in
`phpunit.dist.xml`): a test sees the database the migrations left, whatever ran before. Anything
written outside PHPUnit (a `php -r` probe in the test environment) stays: recreate the database.

The test environment never reaches the directory: `tests/Support/InMemoryDirectory` holds the
people of `frankenphp/ldap/10-tree.ldif` (password `peyra-dev`) and lets a test add a person, rename
a login or take the directory down - keep the client's kernel (`$client->disableReboot()`) for the
change to last past the next request. `tests/Support/SignsIn` signs in through the real form.
The sign-in limiters count in memory, one kernel at a time.

A test that changes an environment variable (`$_ENV`, `$_SERVER`) puts back the value it found,
never `unset()`s it: `.env` declares every variable, and the tests after it in the same process
(`bin/phpunit`, not ParaTest) would boot without it (« Environment variable not found »). `LdapDirectoryTest` alone reads the
real development directory, which CI starts too.

## The test database

The test environment opens `<database>_test` (`config/packages/doctrine.yaml`, `dbname_suffix`),
separate from the development database: `peyra_test` with the values of `.env.dev.local.example`.
The application user may create and own every `peyra_test…` database: the MariaDB container grants
it on its first start (`frankenphp/mariadb/10-test-databases.sh`, run on an empty volume only).

On a volume created before that script existed, grant it once by hand:

```console
docker compose exec database sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "
  GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}\\_test%\`.* TO \"$MARIADB_USER\"@\"%\"; FLUSH PRIVILEGES;"'
```

Then create it and bring it to the schema, the first time and after every new migration:

```console
docker compose exec -e APP_ENV=test php bin/console doctrine:database:create --if-not-exists
docker compose exec -e APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

`--allow-no-migration` only matters until the first migration exists.

## Running the suite in parallel

ParaTest runs the suite in several processes, and each process opens its own database,
`<database>_test<N>`: ParaTest sets `TEST_TOKEN` to the process number, and `dbname_suffix` appends
it. Those databases are copies of `<database>_test`, tables and rows, written by
`app:test:parallel-databases` (dropped and written again at each run, so running it after a schema
change is the whole upgrade). Warm the test cache first, or the processes all compile the container
into the same directory at once:

```console
docker compose exec -e APP_ENV=test php bin/console app:test:parallel-databases --processes=4
docker compose exec -e APP_ENV=test php bin/console cache:warmup
docker compose exec -e APP_ENV=test php vendor/bin/paratest --processes=4
```

Tests are handed out to the processes by file. A helper class shared by several test files needs a
file of its own: ParaTest does not load the file that happens to declare it first.

## Replaying CI locally

CI (`.github/workflows/ci.yaml`) runs the same commands with `TEST_TOKEN=ci`, so its migrations land
in `peyra_testci`: replaying its sequence on a workstation leaves your own `peyra_test` alone.

```console
docker compose exec -e APP_ENV=test -e TEST_TOKEN=ci php bin/console doctrine:database:create --if-not-exists
docker compose exec -e APP_ENV=test -e TEST_TOKEN=ci php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
docker compose exec -e APP_ENV=test -e TEST_TOKEN=ci php bin/console doctrine:schema:validate
```
