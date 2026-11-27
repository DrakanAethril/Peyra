<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « Soldes lus, jamais stockés » (README of the plan, § 8; conception § 5): no column of the schema
 * keeps a balance, an outstanding amount or a running total - they are read by LedgerReader from
 * the entry lines. A field named so fails this test until it is named for what it really holds.
 */
final class NoStoredBalanceTest extends KernelTestCase
{
    private const string PATTERN = '/balance|solde|encours|outstanding|running/i';

    public function testNoColumnHoldsABalance(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $offenders = [];
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->getFieldNames() as $field) {
                if (1 === preg_match(self::PATTERN, $field.' '.$metadata->getColumnName($field))) {
                    $offenders[] = $metadata->getName().'::'.$field;
                }
            }
        }

        self::assertSame([], $offenders, 'Un solde se lit (LedgerReader), il ne se stocke pas.');
    }
}
