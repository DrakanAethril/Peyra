<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Identity\EntryRefusal;
use App\Service\Identity\EntryDecision;
use App\Service\Identity\EntryRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The entry rule of the README of the plan, § 9, case by case. */
final class EntryRuleTest extends TestCase
{
    private const array CG = ['cg-1', 'cg-2'];
    private const array ADMINS = ['prof.martin'];

    /**
     * @param list<string> $groups
     */
    #[DataProvider('cases')]
    public function testDecides(string $login, array $groups, bool $named, ?AccountKind $kind, ?EntryBasis $basis, ?EntryRefusal $refusal): void
    {
        $decision = EntryRule::decide($login, $groups, self::CG, self::CG, self::ADMINS, $named);

        self::assertSame([$kind, $basis, $refusal], [$decision->kind, $decision->basis, $decision->refusal]);
    }

    /** @return iterable<string, array{string, list<string>, bool, ?AccountKind, ?EntryBasis, ?EntryRefusal}> */
    public static function cases(): iterable
    {
        yield 'étudiant CG' => ['ines.morel', ['student', 'cg-1'], false, AccountKind::Student, EntryBasis::StudentGroup, null];
        yield 'étudiant hors CG' => ['enzo.petit', ['student', 'sio-1'], false, null, null, EntryRefusal::NotInProgramme];
        yield 'enseignant CG' => ['prof.durand', ['teacher', 'cg-1'], false, AccountKind::Teacher, EntryBasis::TeacherGroup, null];
        yield 'enseignant hors filière' => ['prof.leroy', ['teacher', 'sio-1'], false, null, null, EntryRefusal::TeacherNotAuthorized];
        yield 'administrateur par variable, hors groupe CG' => ['prof.martin', ['teacher'], false, AccountKind::Teacher, EntryBasis::BootstrapList, null];
        yield 'enseignant autorisé nommément' => ['prof.leroy', ['teacher', 'sio-1'], true, AccountKind::Teacher, EntryBasis::NamedAuthorization, null];
        yield 'le groupe passe avant la liste d’amorçage' => ['prof.martin', ['teacher', 'cg-1', 'cg-2'], false, AccountKind::Teacher, EntryBasis::TeacherGroup, null];
        yield 'la liste d’amorçage passe avant l’autorisation' => ['prof.martin', ['teacher'], true, AccountKind::Teacher, EntryBasis::BootstrapList, null];
        yield 'étudiant de la liste d’amorçage : pas enseignant' => ['prof.martin', ['student', 'cg-1'], false, AccountKind::Student, EntryBasis::StudentGroup, null];
        yield 'étudiant autorisé nommément hors CG : refusé' => ['enzo.petit', ['student', 'sio-1'], true, null, null, EntryRefusal::NotInProgramme];
        yield 'membre CG sans rôle' => ['agent.x', ['cg-1'], false, null, null, EntryRefusal::NotInProgramme];
        yield 'administrateur de Formatool sans groupe' => ['admin', ['admin'], false, null, null, EntryRefusal::NotInProgramme];
        yield 'enseignant hors filière mais étudiant CG' => ['jo.double', ['teacher', 'student', 'cg-1'], false, AccountKind::Teacher, EntryBasis::TeacherGroup, null];
        yield 'casse des groupes et de l’identifiant' => ['Prof.Martin', ['Teacher'], false, AccountKind::Teacher, EntryBasis::BootstrapList, null];
    }

    public function testATeacherGroupListOtherThanTheStudentsIsHeld(): void
    {
        $decision = EntryRule::decide('prof.durand', ['teacher', 'cg-1'], ['cg-1'], ['cg-2'], [], false);

        self::assertFalse($decision->isAdmitted());
        self::assertSame(EntryRefusal::TeacherNotAuthorized, $decision->refusal);
    }

    public function testATeacherTheRuleRefusesStillEntersAsACgStudent(): void
    {
        $decision = EntryRule::decide('jo.double', ['teacher', 'student', 'cg-2'], ['cg-2'], ['cg-1'], [], false);

        self::assertSame([AccountKind::Student, EntryBasis::StudentGroup], [$decision->kind, $decision->basis]);
    }

    public function testRefusalsSayNothingTechnical(): void
    {
        self::assertSame('Peyra est réservé aux étudiants et aux enseignants du BTS CG. Votre compte n’en fait pas partie.', EntryRefusal::NotInProgramme->message());
        self::assertSame('Votre compte n’est pas encore autorisé dans Peyra. Demandez à un administrateur de vous ajouter aux enseignants de la filière.', EntryRefusal::TeacherNotAuthorized->message());
    }

    public function testListsOfTheEnvironment(): void
    {
        self::assertSame(['cg-1', 'cg-2'], EntryRule::listOf(' cg-1, ,cg-2 ,'));
        self::assertSame([], EntryRule::listOf(''));
        self::assertTrue(EntryRule::isBootstrapAdministrator('PROF.MARTIN', ['prof.martin']));
        self::assertFalse(EntryRule::isBootstrapAdministrator('prof.durand', ['prof.martin']));
    }

    public function testADecisionIsEitherAdmittedOrRefused(): void
    {
        $admitted = EntryDecision::admit(AccountKind::Student, EntryBasis::StudentGroup);
        $refused = EntryDecision::refuse(EntryRefusal::NotInProgramme);

        self::assertTrue($admitted->isAdmitted());
        self::assertNull($admitted->refusal);
        self::assertFalse($refused->isAdmitted());
        self::assertNull($refused->kind);
    }
}
