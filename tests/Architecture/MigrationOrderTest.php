<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Migrations run in the order of their names, from an empty database in CI and in production.
 * Two tracks write migrations in parallel: one named before the migration that creates its table
 * (born on a branch, merged after the other) replays on a development database that already has
 * the table, and fails on an empty one. This reads the files, no database: every table a
 * migration's up() alters, updates, inserts into, deletes from, indexes or references by a foreign
 * key is created by itself or by a migration of an earlier name.
 */
final class MigrationOrderTest extends TestCase
{
    private const string NAME = '`?([A-Za-z0-9_]+)`?';

    public function testEveryTableIsCreatedByAnEarlierMigration(): void
    {
        $files = glob(\dirname(__DIR__, 2).'/migrations/Version*.php');
        self::assertNotFalse($files);
        sort($files, \SORT_STRING);
        self::assertNotSame([], $files);

        $created = [];
        $faults = [];
        foreach ($files as $file) {
            $statements = self::upStatements($file);
            foreach ($statements as $sql) {
                foreach (self::created($sql) as $table) {
                    $created[$table] = true;
                }
            }
            foreach ($statements as $sql) {
                foreach (self::named($sql) as $table) {
                    if (!isset($created[$table])) {
                        $faults[] = basename($file).' : '.$table;
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($faults)), 'Une migration nomme une table qu’aucune migration de nom antérieur ne crée : renomme-la après la dernière migration de staging (CLAUDE.md, « Migrations born on two tracks »).');
    }

    public function testTheReadingRecognisesEachStatement(): void
    {
        self::assertSame(['a'], self::created('CREATE TABLE a (id INT)'));
        self::assertSame(['b'], self::created('RENAME TABLE a TO b'));
        self::assertSame(['a'], self::named('ALTER TABLE a ADD x INT'));
        self::assertSame(['a', 'b'], self::named('ALTER TABLE a ADD CONSTRAINT FK_1 FOREIGN KEY (b_id) REFERENCES b (id)'));
        self::assertSame(['a'], self::named("UPDATE a SET x = 'b'"));
        self::assertSame(['a'], self::named('INSERT INTO a (x) VALUES (1)'));
        self::assertSame(['a'], self::named('DELETE FROM a WHERE x = 1'));
        self::assertSame(['a'], self::named('CREATE UNIQUE INDEX i ON a (x)'));
        self::assertSame(['a'], self::named('DROP INDEX i ON `a`'));
        self::assertSame(['c'], self::named('CREATE TABLE a (id INT, CONSTRAINT f FOREIGN KEY (c_id) REFERENCES c (id))'));
    }

    /**
     * @return list<string> the SQL of every addSql() call of up()
     */
    private static function upStatements(string $file): array
    {
        $source = (string) file_get_contents($file);
        $start = strpos($source, 'function up(');
        self::assertNotFalse($start, basename($file).' n’a pas de méthode up().');
        $end = strpos($source, 'function down(', $start);
        $body = substr($source, $start, false === $end ? null : $end - $start);

        preg_match_all('/addSql\(\s*([\'"])(.*?)(?<!\\\\)\1/s', $body, $matches);

        return $matches[2];
    }

    /**
     * @return list<string>
     */
    private static function created(string $sql): array
    {
        $tables = [];
        if (1 === preg_match('/^\s*CREATE TABLE (?:IF NOT EXISTS )?'.self::NAME.'/i', $sql, $match)) {
            $tables[] = strtolower($match[1]);
        }
        if (1 === preg_match('/^\s*RENAME TABLE '.self::NAME.' TO '.self::NAME.'/i', $sql, $match)) {
            $tables[] = strtolower($match[2]);
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private static function named(string $sql): array
    {
        $patterns = [
            '/^\s*ALTER TABLE '.self::NAME.'/i',
            '/^\s*UPDATE '.self::NAME.'/i',
            '/^\s*INSERT (?:IGNORE )?INTO '.self::NAME.'/i',
            '/^\s*DELETE FROM '.self::NAME.'/i',
            '/^\s*(?:CREATE|DROP)\s.*?\bINDEX\s+\S+\s+ON\s+'.self::NAME.'/is',
            '/\bREFERENCES\s+'.self::NAME.'/i',
        ];
        $tables = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $sql, $matches);
            foreach ($matches[1] as $table) {
                $tables[] = strtolower($table);
            }
        }

        return array_values(array_unique($tables));
    }
}
