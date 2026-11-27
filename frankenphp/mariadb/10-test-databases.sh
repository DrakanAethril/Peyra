#!/bin/sh
# Run by the MariaDB image on an empty data volume only (docker-entrypoint-initdb.d).
#
# Lets the application user create and own every `<database>_test…` database: `_test` for
# bin/phpunit, `_testci` on CI, `_test1`… one per ParaTest process (tests/README.md). On a volume
# created before this file existed, run the same GRANT once by hand (tests/README.md).
#
# Executable on purpose: the image sources a non-executable script, but Docker Desktop reports
# bind-mounted files as executable anyway, so the script is written to be run, on Linux and macOS.
set -e

mariadb --protocol=socket -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}\\_test%\`.* TO '${MARIADB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
