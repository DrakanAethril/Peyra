#syntax=docker/dockerfile:1

# Pinned to a FrankenPHP minor line, as Formatool does: a minor can change what the Caddyfile is
# allowed to say (1.13 bundled the Mercure 1.0 hub, see frankenphp/Caddyfile). Move the pin
# deliberately when a new minor ships.
FROM dunglas/frankenphp:1.13-php8.5 AS frankenphp_upstream


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# Every PHP extension the whole project needs, installed from the first lot so that no later step
# has to rebuild the image: intl (French formatting), pdo_mysql (MariaDB), pdo_sqlite (the SQL
# tool's teaching export, lot 5), ldap (sign-in), zip (exports), gd (generated documents), bcmath
# (exact decimals behind brick/math), opcache and apcu.
# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git
	install-php-extensions \
		@composer \
		apcu \
		bcmath \
		gd \
		intl \
		ldap \
		opcache \
		pdo_mysql \
		pdo_sqlite \
		zip
	rm -rf /var/lib/apt/lists/*
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]


# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	useradd -m -s /bin/bash nonroot
	git config --system --add safe.directory /app
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]


# Builder for the prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod_builder

ENV APP_ENV=prod
# Build-time placeholders only: compiling the container resolves these, the running container gets
# its real values from compose.prod.yaml (Compose's `environment:` always wins over an image ENV).
ENV MERCURE_URL=http://php/.well-known/mercure \
	MERCURE_PUBLIC_URL=http://php/.well-known/mercure

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	mkdir -p var/cache var/log var/share
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	# The build's cache:clear compiles the whole container and every template in one process;
	# a throwaway ini gives it room and is removed before the final image copies app.conf.d/.
	echo 'memory_limit = 512M' > "$PHP_INI_DIR/app.conf.d/99-build-only-memory-limit.ini"
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	rm "$PHP_INI_DIR/app.conf.d/99-build-only-memory-limit.ini"
	chmod +x bin/console
	chmod -R g=u var
	sync
EOF

# Collect the shared libraries FrankenPHP and the PHP extensions need
# hadolint ignore=DL3008,SC3054,DL4006
RUN <<-'EOF'
	apt-get update
	apt-get install -y --no-install-recommends libtree
	mkdir -p /tmp/libs
	BINARIES=(frankenphp php file)
	for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			[ -f "$lib" ] && cp -n "$lib" /tmp/libs/
		done
	done
	rm -rf /var/lib/apt/lists/*
EOF


# Prod FrankenPHP image
FROM debian:13-slim AS frankenphp_prod

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

COPY --from=frankenphp_prod_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp_prod_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=frankenphp_prod_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=frankenphp_prod_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=frankenphp_prod_builder /tmp/libs /usr/lib

COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# CA certificates for TLS, file/libmagic for Symfony's MIME type detection
COPY --from=frankenphp_prod_builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=frankenphp_prod_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=frankenphp_prod_builder /usr/bin/file /usr/bin/file
COPY --from=frankenphp_prod_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

ENV OPENSSL_CONF=/etc/ssl/openssl.cnf XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt

RUN <<-EOF
	mkdir -p /data/caddy /config/caddy
	chown -R www-data:www-data /data /config
	# Remove setuid/setgid bits
	find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

COPY --link --exclude=var --from=frankenphp_prod_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes.
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
RUN chmod g=u /app/var

# Sessions, locks and the local storage fallback live under var/ on named volumes
# (compose.prod.yaml); pre-create them so the volumes' first seeding copies these permissions.
RUN mkdir -p /app/var/sessions /app/var/lock /app/var/storage \
	&& chown www-data:0 /app/var/sessions /app/var/lock /app/var/storage \
	&& chmod g=u /app/var/sessions /app/var/lock /app/var/storage

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

USER www-data

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
