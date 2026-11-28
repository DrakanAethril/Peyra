<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * « Le corrigé ne sort pas » (README of the plan, § 8): what a pièce keeps of the answer key - the
 * errors the teacher slipped in and the right values (`answerKey`), the first arrival a doublon
 * repeats (`duplicateOf`), what the generator made it from and its faults (`generation`) - is read
 * by the pièce's preparation and the doors that write it, nowhere else: no other controller, no
 * other template, so no student screen. The correction (step 2.11) will join the list. A file that
 * names one of them outside the list fails this test.
 *
 * A mission's answer key (step 2.9b) - MissionAnswerKey, its repository, AnswerKeys and AnswerKeyReading,
 * the `answer_key` of its screen - is named in the same way only by its entity, its door and the
 * Corrigé tab; elsewhere one asks AnswerKeyStatus, which says whether one is recorded and nothing of
 * what it holds.
 */
final class AnswerKeyStaysHomeTest extends TestCase
{
    /** Where the answer key may be named, relative to the project (a directory ends with a slash). */
    private const array ALLOWED = [
        'src/Entity/Document/Document.php',
        'src/Repository/Document/DocumentRepository.php',
        'src/Service/Document/Documents.php',
        'src/Service/Document/Generator/InvoiceGenerator.php',
        'src/Service/Document/Preparation/',
        'templates/document/prepare.html.twig',
        'templates/document/_slip_panel.html.twig',
        'src/Entity/Mission/MissionAnswerKey.php',
        'src/Repository/Mission/MissionAnswerKeyRepository.php',
        'src/Service/Mission/AnswerKey/',
        'src/Controller/Mission/MissionAnswerKeyController.php',
        'templates/mission/answer_key.html.twig',
    ];

    private const string NAMES = '/\b(getAnswerKey|keepAnswerKey|answerKey|getDuplicateOf|markDuplicateOf|duplicateOf|getGeneration|generatedFrom|MissionAnswerKey|MissionAnswerKeyRepository|AnswerKeys|AnswerKeyReading)\b|\.generation\b|\banswer_key\./';

    public function testOnlyThePreparationReadsTheAnswerKey(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];
        foreach (['src', 'templates'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof \SplFileInfo || !\in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }
                $relative = substr($file->getPathname(), \strlen($root) + 1);
                if (self::allowed($relative)) {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                if (1 === preg_match(self::NAMES, $source)) {
                    $offenders[] = $relative;
                }
            }
        }
        sort($offenders);

        self::assertSame([], $offenders, 'Le corrigé d’une pièce ne se lit qu’en mode préparation.');
    }

    public function testTheScannerFindsEachName(): void
    {
        foreach (['$document->getAnswerKey()', '{{ document.answerKey }}', '$d->getDuplicateOf()', '{{ document.generation.faults }}', '$d->getGeneration()', 'use App\\Entity\\Mission\\MissionAnswerKey;', 'MissionAnswerKeyRepository $keys', 'AnswerKeys $doors', '{{ answer_key.entries }}'] as $source) {
            self::assertSame(1, preg_match(self::NAMES, $source), $source);
        }
        self::assertSame(0, preg_match(self::NAMES, '{{ document.generated ? "Lecture automatique" : "" }}'));
        self::assertSame(0, preg_match(self::NAMES, 'AnswerKeyStatus $status; {{ answer_key_mission(dossier) }}'));
    }

    private static function allowed(string $relative): bool
    {
        foreach (self::ALLOWED as $allowed) {
            if (str_ends_with($allowed, '/') ? str_starts_with($relative, $allowed) : $relative === $allowed) {
                return true;
            }
        }

        return false;
    }
}
