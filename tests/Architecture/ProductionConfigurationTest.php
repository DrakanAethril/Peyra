<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * What production behind the establishment's nginx needs from the repository (README of the plan,
 * § 9; docs/production.md), held by reading the files themselves: the server's own files cannot
 * drift from what the application reads, and nothing assumes the development stack.
 */
final class ProductionConfigurationTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** Read only by the test environment. */
    private const array TEST_ONLY = ['TEST_TOKEN'];

    public function testTheProductionTemplateNamesEveryVariableTheApplicationReads(): void
    {
        $template = $this->templateNames();
        $fromCompose = array_merge(
            array_keys($this->environment('compose.yaml', 'php')),
            array_keys($this->environment('compose.prod.yaml', 'php')),
        );

        $missing = array_values(array_diff($this->variablesRead(), $template, $fromCompose, self::TEST_ONLY));

        self::assertSame([], $missing, 'Every variable production reads is in .env.prod.local.example, or set by compose.prod.yaml.');
        self::assertContains('MERCURE_PUBLISHER_JWT_KEY', $template, 'The hub of the Caddyfile reads it.');
        self::assertContains('MERCURE_SUBSCRIBER_JWT_KEY', $template, 'The hub of the Caddyfile reads it.');
    }

    public function testTheProductionTemplateHoldsNoSecret(): void
    {
        foreach ($this->templateLines() as $name => $value) {
            if (1 === preg_match('/(SECRET|PASSWORD|_KEY)$/', $name)) {
                self::assertSame('', $value, $name.' is left empty in the template.');
            }
        }
        self::assertStringContainsString('MOT_DE_PASSE@ADRESSE_DU_SERVEUR_MARIADB', $this->templateLines()['DATABASE_URL']);
    }

    public function testTheContainerServesPlainHttpOnOnePortBehindTheProxy(): void
    {
        $compose = $this->yaml('compose.prod.yaml');
        self::assertIsArray($compose['services'] ?? null);
        self::assertSame(['php', 'gotenberg', 'worker'], array_keys($compose['services']), 'No database: production uses the MariaDB server Formatool uses.');

        $php = $this->environment('compose.prod.yaml', 'php');
        self::assertSame(':80', $php['SERVER_NAME'], 'A host name would make Caddy listen on 443 even with automatic HTTPS off.');
        self::assertSame('auto_https off', $php['CADDY_GLOBAL_OPTIONS']);
        self::assertStringStartsWith('${PUBLIC_URL:?', self::text($php['DEFAULT_URI'] ?? null));
        self::assertStringStartsWith('${PUBLIC_URL:?', self::text($php['MERCURE_PUBLIC_URL'] ?? null));
        self::assertStringContainsString('Strict-Transport-Security', self::text($php['CADDY_SERVER_EXTRA_DIRECTIVES'] ?? null));

        $ports = $this->service('compose.prod.yaml', 'php')['ports'] ?? null;
        self::assertInstanceOf(TaggedValue::class, $ports, 'The ports replace those of compose.yaml (`!override`), they are not added to them.');
        self::assertSame('override', $ports->getTag());
        self::assertSame([['name' => 'http', 'target' => 80, 'published' => '${HTTP_PORT:-8080}', 'protocol' => 'tcp']], $ports->getValue());
    }

    public function testPhpAndWorkerShareWhatTheyMustAndOnlyPhpMigrates(): void
    {
        $php = $this->service('compose.prod.yaml', 'php');
        $worker = $this->service('compose.prod.yaml', 'worker');

        self::assertSame(['docker-php-entrypoint'], $worker['entrypoint'] ?? null, 'The worker bypasses the entrypoint, which migrates.');
        self::assertArrayNotHasKey('entrypoint', $php);
        foreach (['php' => $php, 'worker' => $worker] as $name => $service) {
            $environment = $this->environment('compose.prod.yaml', $name);
            self::assertSame('${PEYRA_VERSION:-}', $environment['PEYRA_VERSION'] ?? null, $name);
            self::assertSame('flock:///app/var/lock', $environment['LOCK_DSN'] ?? null, $name);
            self::assertContains('app_locks:/app/var/lock', $service['volumes'] ?? [], $name);
            self::assertContains('app_storage:/app/var/storage', $service['volumes'] ?? [], $name.' sees the files PEYRA_STORAGE=local writes.');
            self::assertContains('./certs:/etc/peyra/certs:ro', $service['volumes'] ?? [], $name);
            self::assertSame(['.env.prod.local'], $service['env_file'] ?? null, $name);
        }
        self::assertSame($this->environment('compose.prod.yaml', 'php')['DEFAULT_URI'], $this->environment('compose.prod.yaml', 'worker')['DEFAULT_URI']);
        self::assertContains('php_sessions:/app/var/sessions', $php['volumes'] ?? []);
        self::assertContains('caddy_data:/data', $this->service('compose.yaml', 'php')['volumes'] ?? []);

        foreach (['php', 'gotenberg', 'worker'] as $name) {
            self::assertSame('json-file', self::at($this->service('compose.prod.yaml', $name), 'logging', 'driver'), $name.'’s logs are bounded.');
        }

        $entrypoint = (string) file_get_contents(self::ROOT.'/frankenphp/docker-entrypoint.sh');
        self::assertStringContainsString('doctrine:migrations:migrate', $entrypoint);
        self::assertStringNotContainsString('database:create', $entrypoint, 'The production user has rights on its own base only.');
        self::assertStringNotContainsString('--all-or-nothing', $entrypoint, 'It refuses non-transactional migrations.');
    }

    public function testNoMigrationPretendsToBeTransactionalOnMariaDb(): void
    {
        self::assertFalse(self::at($this->yaml('config/packages/doctrine_migrations.yaml'), 'doctrine_migrations', 'transactional'));
        $files = glob(self::ROOT.'/migrations/*.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            self::assertMatchesRegularExpression('/function isTransactional\(\): bool\s*\{\s*return false;/', (string) file_get_contents($file), basename($file).': MariaDB commits around every DDL statement.');
        }
    }

    public function testTheApplicationBelievesItsProxyAndKeepsTheSessionCookieSecure(): void
    {
        $framework = $this->yaml('config/packages/framework.yaml');

        self::assertSame('%env(TRUSTED_PROXIES)%', self::at($framework, 'framework', 'trusted_proxies'));
        self::assertSame(['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-port'], self::at($framework, 'framework', 'trusted_headers'));
        self::assertSame('lax', self::at($framework, 'framework', 'session', 'cookie_samesite'));
        self::assertTrue(self::at($framework, 'when@prod', 'framework', 'session', 'cookie_secure'));
    }

    public function testEveryAnswerCarriesTheBaseSecurityHeaders(): void
    {
        $caddyfile = (string) file_get_contents(self::ROOT.'/frankenphp/Caddyfile');

        foreach (['?X-Content-Type-Options nosniff', '?X-Frame-Options SAMEORIGIN', '?Referrer-Policy strict-origin-when-cross-origin', '-Server'] as $header) {
            self::assertStringContainsString($header, $caddyfile);
        }
    }

    public function testTheServersOwnFilesNeverReachGitNorTheImage(): void
    {
        $gitignore = (string) file_get_contents(self::ROOT.'/.gitignore');
        $dockerignore = (string) file_get_contents(self::ROOT.'/.dockerignore');

        foreach (['/deploy-prod.sh', '/certs/', '/.env.*.local'] as $pattern) {
            self::assertStringContainsString($pattern."\n", $gitignore);
        }
        foreach (['deploy-prod.sh', 'certs/', '.env.*.local'] as $pattern) {
            self::assertStringContainsString("\n".$pattern."\n", $dockerignore);
        }
    }

    /** @return list<string> every environment variable named by config/ and src/ */
    private function variablesRead(): array
    {
        $names = [];
        $files = glob(self::ROOT.'/config/{packages/*.yaml,services.yaml,routes/*.yaml}', \GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            preg_match_all('/%env\((?:[a-z_]+:)*:?([A-Z][A-Z0-9_]*)\)%/', (string) file_get_contents($file), $matches);
            $names = [...$names, ...$matches[1]];
        }
        $sources = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($sources as $file) {
            \assert($file instanceof \SplFileInfo);
            preg_match_all('/Autowire\(env: \'(?:[a-z_]+:)*([A-Z][A-Z0-9_]*)\'\)/', (string) file_get_contents($file->getPathname()), $matches);
            $names = [...$names, ...$matches[1]];
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function templateNames(): array
    {
        return array_keys($this->templateLines());
    }

    /** @return array<string, string> the template's variables and their values, quotes removed */
    private function templateLines(): array
    {
        $lines = [];
        foreach (file(self::ROOT.'/.env.prod.local.example', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (1 === preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $match)) {
                $lines[$match[1]] = trim($match[2], '\'"');
            }
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function environment(string $file, string $service): array
    {
        $environment = $this->service($file, $service)['environment'] ?? [];
        self::assertIsArray($environment);

        /* @var array<string, mixed> $environment */
        return $environment;
    }

    /** @return array<string, mixed> */
    private function service(string $file, string $name): array
    {
        $service = self::at($this->yaml($file), 'services', $name);
        self::assertIsArray($service, $file.' declares '.$name);

        /* @var array<string, mixed> $service */
        return $service;
    }

    /** The value under these keys, or null when one of them is missing. */
    private static function at(mixed $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return array<string, mixed> */
    private function yaml(string $file): array
    {
        $parsed = Yaml::parseFile(self::ROOT.'/'.$file, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($parsed);

        /* @var array<string, mixed> $parsed */
        return $parsed;
    }
}
