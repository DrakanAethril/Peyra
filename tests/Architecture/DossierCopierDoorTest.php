<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * « Copie profonde complète » (README of the plan, § 8) has one door: a dossier's files are copied
 * by App\Service\Dossier\Copy\DossierCopier alone and deleted by App\Service\Dossier\Copy\DossierEraser
 * alone, so that no gesture copies or empties a dossier around them - and around their
 * completeness test (DossierCopierTest). Read token by token: a comment or a string is no finding.
 */
final class DossierCopierDoorTest extends TestCase
{
    /** @var array<string, string> method => the one file that calls it */
    private const array DOORS = [
        'copyAll' => 'Service/Dossier/Copy/DossierCopier.php',
        'deleteAll' => 'Service/Dossier/Copy/DossierEraser.php',
    ];

    public function testOnlyTheCopierCopiesAndOnlyTheEraserDeletesADossiersFiles(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $offenders = [];
        $seen = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            foreach (self::calls($source) as [$line, $method]) {
                if (self::DOORS[$method] === $relative) {
                    $seen[$method] = true;
                } else {
                    $offenders[] = $relative.':'.$line.' '.$method.'()';
                }
            }
        }

        self::assertSame([], $offenders, 'Un dossier se copie par DossierCopier et s’efface par DossierEraser, seuls.');
        self::assertSame(array_keys(self::DOORS), array_keys($seen), 'Le scanner doit voir les deux portes.');
    }

    /** @return list<array{int, string}> */
    private static function calls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));
        $calls = [];
        foreach ($tokens as $i => $token) {
            if (\is_array($token) && \T_STRING === $token[0] && \array_key_exists($token[1], self::DOORS)) {
                $previous = $tokens[$i - 1] ?? null;
                if (\is_array($previous) && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    $calls[] = [$token[2], $token[1]];
                }
            }
        }

        return $calls;
    }
}
