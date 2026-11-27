<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Chart;

use App\Enum\Accounting\AccountNature;
use App\Service\Accounting\Chart\AccountNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The shape of an account number, its nature and its defaults, all read from the digits. */
final class AccountNumberTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function numbers(): iterable
    {
        yield 'six chiffres' => ['601000', null];
        yield 'huit chiffres' => ['60110000', null];
        yield 'vide' => ['', 'Indiquez le numéro du compte.'];
        yield 'un tiers' => ['401SCIE', 'Un numéro de compte ne s’écrit qu’avec des chiffres ; un compte de tiers comme 401SCIE est le code d’un tiers.'];
        yield 'trop court' => ['601', 'Un numéro de compte compte de 6 à 8 chiffres (601000, 6011000…).'];
        yield 'trop long' => ['601100000', 'Un numéro de compte compte de 6 à 8 chiffres (601000, 6011000…).'];
        yield 'classe 9' => ['901000', 'Un numéro de compte commence par sa classe, de 1 à 8.'];
        yield 'classe 0' => ['012345', 'Un numéro de compte commence par sa classe, de 1 à 8.'];
    }

    #[DataProvider('numbers')]
    public function testAFaultySpellsWhatIsWrong(string $number, ?string $fault): void
    {
        self::assertSame($fault, AccountNumber::fault($number));
    }

    public function testSpacesTypedAreDropped(): void
    {
        self::assertSame('601000', AccountNumber::normalize(' 601 000 '));
    }

    public function testTheNatureIsReadFromTheNumber(): void
    {
        $natures = [
            '101300' => AccountNature::Equity, '151000' => AccountNature::Provision, '164100' => AccountNature::Borrowing,
            '215400' => AccountNature::FixedAsset, '281540' => AccountNature::Depreciation, '310000' => AccountNature::Inventory,
            '391000' => AccountNature::Depreciation, '401000' => AccountNature::Supplier, '411000' => AccountNature::Customer,
            '421000' => AccountNature::Employee, '431000' => AccountNature::SocialBody, '445660' => AccountNature::State,
            '455100' => AccountNature::OtherThirdParty, '491000' => AccountNature::Depreciation, '512100' => AccountNature::Bank,
            '511200' => AccountNature::OtherFinancial, '519000' => AccountNature::OtherFinancial, '530000' => AccountNature::Cash,
            '580000' => AccountNature::OtherFinancial, '590000' => AccountNature::Depreciation, '601000' => AccountNature::Expense,
            '701000' => AccountNature::Revenue, '801100' => AccountNature::Commitment,
        ];
        foreach ($natures as $number => $nature) {
            self::assertSame($nature, AccountNature::of((string) $number), (string) $number);
        }
    }

    public function testThirdPartiesAccountsAreLettrableAndSuppliersAndCustomersCollective(): void
    {
        self::assertTrue(AccountNumber::isLettrableByDefault('401000'));
        self::assertTrue(AccountNumber::isLettrableByDefault('467000'));
        self::assertFalse(AccountNumber::isLettrableByDefault('445660'), 'La TVA ne se lettre pas par défaut.');
        self::assertFalse(AccountNumber::isLettrableByDefault('512100'));
        self::assertFalse(AccountNumber::isLettrableByDefault('601000'));
        self::assertFalse(AccountNumber::isLettrableByDefault('486000'), 'Les charges constatées d’avance ne se lettrent pas.');

        foreach (['401000', '403000', '404000', '409100', '411000', '413000', '416000', '419100'] as $collective) {
            self::assertTrue(AccountNumber::isCollectiveByDefault($collective), $collective);
        }
        foreach (['408100', '421000', '431000', '445660', '512100', '601000'] as $plain) {
            self::assertFalse(AccountNumber::isCollectiveByDefault($plain), $plain);
        }
        self::assertTrue(AccountNumber::mayBeCollective('467000'));
        self::assertFalse(AccountNumber::mayBeCollective('512100'));
    }
}
