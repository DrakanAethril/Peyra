<?php

declare(strict_types=1);

namespace App\Tests\Service\ThirdParty;

use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\ThirdParty\AuxiliaryCode;
use App\Service\ThirdParty\ThirdPartyCode;
use PHPUnit\Framework\TestCase;

/** A third party's code (« C0003 ») and its auxiliary code (« 401SCIE »): shape, next code, proposal. */
final class ThirdPartyCodesTest extends TestCase
{
    public function testTheShapeOfACode(): void
    {
        self::assertSame('C0003', ThirdPartyCode::normalize(' c 0003 '));
        self::assertNull(ThirdPartyCode::fault('C0003'));
        self::assertNull(ThirdPartyCode::fault('ABCDEFGHIJ12'));
        self::assertSame('Indiquez le code du tiers.', ThirdPartyCode::fault(''));
        self::assertNotNull(ThirdPartyCode::fault('C-0003'));
        self::assertNotNull(ThirdPartyCode::fault('ABCDEFGHIJ123'));
        self::assertNotNull(ThirdPartyCode::fault('É001'));
    }

    public function testTheNextCodeFollowsTheHighestOfItsKind(): void
    {
        $codes = ['C0003', 'C0065', 'F0021', 'O0007', 'A0001', 'CLIENT'];
        self::assertSame('C0066', ThirdPartyCode::next(ThirdPartyKind::Customer, $codes));
        self::assertSame('F0022', ThirdPartyCode::next(ThirdPartyKind::Supplier, $codes));
        self::assertSame('S0001', ThirdPartyCode::next(ThirdPartyKind::Employee, $codes));
        self::assertSame('O0008', ThirdPartyCode::next(ThirdPartyKind::Other, $codes));
        self::assertSame('C0001', ThirdPartyCode::next(ThirdPartyKind::Customer, []));
    }

    public function testTheShapeOfAnAuxiliaryCode(): void
    {
        self::assertSame('401SCIE', AuxiliaryCode::normalize('401 scie'));
        self::assertNull(AuxiliaryCode::fault('401SCIE'));
        self::assertNull(AuxiliaryCode::fault('DUMAS'));
        self::assertSame('Un code auxiliaire porte au moins une lettre (401SCIE) : un nombre seul serait un numéro de compte.', AuxiliaryCode::fault('401100'));
        self::assertNotNull(AuxiliaryCode::fault('S'));
        self::assertNotNull(AuxiliaryCode::fault('401SCIERIEDUHAUTFO'));
        self::assertNotNull(AuxiliaryCode::fault('401-SCIE'));
    }

    public function testACodeIsProposedFromTheCollectiveAndTheName(): void
    {
        $free = static fn (string $code): bool => false;
        self::assertSame('401SCIE', AuxiliaryCode::suggest('401000', 'Scierie du Haut-Forez', $free));
        self::assertSame('411GIRA', AuxiliaryCode::suggest('411000', 'Girard, Jean et Anne', $free));
        self::assertSame('401ELEC', AuxiliaryCode::suggest('401000', 'Électricité Loire Énergies', $free));
        self::assertSame('401VASS', AuxiliaryCode::suggest('401000', 'SCI Vasseur Immobilier', $free), 'La forme juridique est passée.');
        self::assertSame('411TIER', AuxiliaryCode::suggest('411000', '42 & 43', $free));
        $taken = static fn (string $code): bool => \in_array($code, ['401SCIE', '401SCIE2'], true);
        self::assertSame('401SCIE3', AuxiliaryCode::suggest('401000', 'Scierie Martin', $taken));
    }
}
