<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll;

use App\Entity\Audit\AuditEvent;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Payroll\Plan\PayrollItemDraft;
use App\Service\Payroll\Plan\PayrollItemSheet;
use App\Service\Payroll\Plan\PayrollPlanInstaller;
use App\Service\Payroll\Settings\PayrollBodyDraft;
use App\Service\Payroll\Settings\PayrollBodySheet;
use App\Service\Payroll\Settings\PayrollEstablishmentDraft;
use App\Service\Payroll\Settings\PayrollEstablishmentRules;
use App\Service\Payroll\Settings\PayrollEstablishmentSheet;
use App\Service\Regulatory\RegulatorySync;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The payroll's settings in a dossier (step 4.1): the standard plan, a rubrique by hand, the bodies, the establishment. */
final class PayrollSettingsTest extends KernelTestCase
{
    use SavesDossiers;

    public function testTheStandardPlanGoesIntoAnEmptyPlanOnce(): void
    {
        [$dossier] = $this->setUpDossier();
        $installer = self::getContainer()->get(PayrollPlanInstaller::class);

        self::assertSame(49, $installer->installStandard($dossier, 'test'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $bodies = self::getContainer()->get(PayrollBodyRepository::class)->findByDossier($dossier);
        self::assertSame([PayrollBodyKind::Urssaf, PayrollBodyKind::Retirement, PayrollBodyKind::Provident], array_map(static fn ($body) => $body->getKind(), $bodies), 'The bodies its contributions go to, made with their default names.');
        $plan = self::getContainer()->get(PayrollItemRepository::class)->findPlan($dossier);
        self::assertSame(['1000', '1010'], [$plan[0]->getCode(), $plan[1]->getCode()], 'The gross pay first.');
        self::assertSame('4200', $plan[48]->getCode(), 'The withholding tax last.');
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->count(['action' => AuditAction::PayrollPlanInstalled]));

        $this->expectException(\LogicException::class);
        $installer->installStandard($dossier, 'test');
    }

    public function testARubriqueByHandReadsItsRegulatoryValueAtTheDossiersDate(): void
    {
        [$dossier, $student] = $this->setUpDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $bodies = self::getContainer()->get(PayrollBodySheet::class);
        $bodyDraft = new PayrollBodyDraft();
        [$bodyDraft->kind, $bodyDraft->name] = ['urssaf', 'Urssaf Rhône-Alpes'];
        $kind = $bodies->check($bodyDraft);
        self::assertNotNull($kind);
        $urssaf = $bodies->create($dossier, $bodyDraft, $kind, $student);
        $entityManager->flush();

        $sheet = self::getContainer()->get(PayrollItemSheet::class);
        $draft = new PayrollItemDraft();
        [$draft->code, $draft->label, $draft->kind, $draft->base] = ['2600', 'Contribution solidarité autonomie', 'contribution', 'gross'];
        [$draft->employerSource, $draft->employerKey, $draft->employerPath] = ['regulatory', 'social.contrib.csa', 'employer_rate'];
        [$draft->body, $draft->line] = [(string) $urssaf->getId(), 'other_employer_contributions'];

        self::assertNull($sheet->check($draft, $dossier), 'No regulatory value installed yet.');
        self::assertSame('La valeur réglementaire social.contrib.csa › employer_rate ne se lit pas au 15/03/2026 : aucune valeur en vigueur, ou pas de nombre à ce chemin (Paramètres › Paramètres réglementaires).', $draft->error('employerKey'));

        self::getContainer()->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $spec = $sheet->check($draft, $dossier);
        self::assertNotNull($spec, implode(' ', $draft->errors));
        $item = $sheet->create($dossier, $spec, $student);
        $entityManager->flush();
        self::assertSame(1, $entityManager->getRepository(AuditEvent::class)->count(['action' => AuditAction::PayrollItemCreated]));

        self::assertNotNull($sheet->check(PayrollItemDraft::of($item), $dossier, $item), 'Its own code is not « taken ».');
        $duplicate = PayrollItemDraft::of($item);
        self::assertNull($sheet->check($duplicate, $dossier));
        self::assertSame('La rubrique 2600 existe déjà : Contribution solidarité autonomie.', $duplicate->error('code'));

        // The body it is paid to stays, and keeps its kind.
        self::assertSame('Une cotisation du plan de paie est versée à cet organisme : il reste au dossier.', $bodies->deletionRefusal($urssaf));
        $bodyDraft = PayrollBodyDraft::of($urssaf);
        $bodyDraft->kind = 'health';
        self::assertNull($bodies->check($bodyDraft, $urssaf));
        self::assertSame('Des cotisations du plan de paie sont versées à cet organisme : il reste « Urssaf ».', $bodyDraft->error('kind'));
    }

    public function testTheEstablishmentIsMadeAtItsFirstSave(): void
    {
        [$dossier, $student] = $this->setUpDossier();
        $sheet = self::getContainer()->get(PayrollEstablishmentSheet::class);
        $draft = new PayrollEstablishmentDraft();
        [$draft->headcount, $draft->workAccidentRate, $draft->mobilityRate, $draft->monthlyHours] = ['8,69', '2,10', '', '151,67'];
        $fields = PayrollEstablishmentRules::check($draft);
        self::assertNotNull($fields);

        self::assertTrue($sheet->save($dossier, $fields, $student));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertFalse($sheet->save($dossier, $fields, $student), 'Nothing changed, nothing written.');
    }

    /** @return array{\App\Entity\Dossier\Dossier, User} */
    private function setUpDossier(): array
    {
        self::bootKernel();
        $student = new User('uid:ines', 'ines.morel', 'Inès Morel', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        self::getContainer()->get(EntityManagerInterface::class)->persist($student);
        $dossier = $this->savedDossier(DossierKind::Student, $student);
        self::getContainer()->get(DossierContext::class)->enter($dossier);

        return [$dossier, $student];
    }
}
