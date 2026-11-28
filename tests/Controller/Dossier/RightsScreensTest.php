<?php

declare(strict_types=1);

namespace App\Tests\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Dossier\DossierMemberRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Profiles and the dossier's trail through HTTP (step 1.7), on a group's Menuiserie Vasseur:
 * ines.morel « Comptable fournisseurs », theo.garnier « Comptable clients », chloe.fabre without a
 * profile, prof.martin teaching their class. Utilisateurs et droits, the profile chip, the grid
 * and the settings narrowed by the profile, the posts refused, the trail and its export.
 */
final class RightsScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testAMemberReadsTheirProfileAndATeacherGivesThem(): void
    {
        [$client, $dossier] = $this->group();

        $this->as($client, 'ines.morel');
        $crawler = $client->request('GET', $this->url($dossier, '/users'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-topbar .px-chip--role', 'Comptable fournisseurs');
        self::assertSelectorTextContains('main .px-alert', 'Votre profil : Comptable fournisseurs');
        self::assertCount(4, $crawler->filter('main .px-card')->first()->filter('tbody tr'), 'Three members and their teacher.');
        self::assertSelectorNotExists('#users-form');
        self::assertSame('Fermé', trim($crawler->filter('.px-rights__matrix tbody tr')->eq(1)->filter('td')->eq(0)->text()), 'The sales are closed to the supplier accountant.');
        $client->request('POST', $this->url($dossier, '/users'), ['_token' => 'any', 'profile' => []]);
        self::assertResponseStatusCodeSame(404);

        $this->as($client, 'prof.martin');
        $crawler = $client->request('GET', $this->url($dossier, '/users'));
        self::assertSelectorNotExists('.px-topbar .px-chip--role');
        $form = $crawler->filter('#users-form');
        $token = (string) $form->filter('input[name="_token"]')->attr('value');
        $chloe = $this->member($client, $dossier, 'chloe.fabre');
        self::assertSame('', $crawler->filter('#profile-'.$chloe->getId().' option[selected]')->attr('value'), 'Chloé has no profile yet.');

        $client->request('POST', $this->url($dossier, '/users'), ['_token' => 'forged', 'profile' => [(string) $chloe->getId() => 'chief_accountant']]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $this->url($dossier, '/users'), ['_token' => $token, 'profile' => [(string) $chloe->getId() => 'chief_accountant']]);
        self::assertResponseRedirects($this->url($dossier, '/users'));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Profil enregistré.');
        self::assertSame(DossierProfile::ChiefAccountant, $this->member($client, $dossier, 'chloe.fabre')->getProfile());

        $crawler = $client->request('GET', $this->url($dossier, '/audit'));
        self::assertResponseIsSuccessful();
        $first = $crawler->filter('main tbody tr')->first();
        self::assertSame('Jean-Pierre Martin Enseignant', self::spaces($first->filter('td')->eq(2)->text()), 'A teacher’s gesture is signed with their name.');
        self::assertSame('15/03/2026', trim($first->filter('td')->eq(1)->text()), 'At the dossier’s date.');
        self::assertStringContainsString('Profil de Chloé Fabre : chef comptable (auparavant : sans profil)', $first->text());
    }

    public function testTheProfileNarrowsTheGridAndTheSettings(): void
    {
        [$client, $dossier] = $this->group();
        $this->as($client, 'ines.morel');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry?journal=VTE&period=2026-03-01'));
        self::assertResponseIsSuccessful();
        $offered = $crawler->filter('select[name="journal"] option')->each(static fn ($option): string => (string) $option->attr('value'));
        self::assertSame(['ACH', 'AN', 'BQ', 'OD'], $offered, 'The sales are not offered; asking them opens the first journal she may.');
        self::assertSelectorTextContains('h1', 'Journal ACH');
        self::assertSelectorExists('#entry-validate');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry?journal=BQ&period=2026-03-01'));
        self::assertSelectorNotExists('#entry-validate');
        self::assertSelectorTextContains('main .px-alert', 'Votre profil “Comptable fournisseurs” ne permet pas de valider le journal de banque.');
        self::assertSame('true', $crawler->filter('.px-entrygrid')->attr('data-entry-grid-editable-value'));

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry?journal=OD&period=2026-03-01'));
        self::assertSame('false', $crawler->filter('.px-entrygrid')->attr('data-entry-grid-editable-value'));
        self::assertSelectorTextContains('main .px-alert', 'ne permet pas de saisir dans le journal d’opérations diverses');

        // A grid's post in a journal she does not enter, whatever the token, is not there.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry?journal=ACH&period=2026-03-01'));
        $token = (string) $crawler->filter('.px-entrygrid')->attr('data-entry-grid-token-value');
        $client->request('POST', $this->url($dossier, '/accounting/entry/keep'), server: ['HTTP_X_PEYRA_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['journal' => 'VTE', 'period' => '2026-03-01', 'groups' => []]));
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $this->url($dossier, '/accounting/entry/account'), server: ['HTTP_X_PEYRA_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['number' => '471999', 'label' => 'Attente']));
        self::assertResponseStatusCodeSame(404, 'Alt Inser creates an account of the plan: the settings.');

        // The settings read without fields; their posts are not there.
        $client->request('GET', $this->url($dossier, '/company'));
        self::assertSelectorNotExists('#company-form');
        $client->request('POST', $this->url($dossier, '/company'), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $this->url($dossier, '/accounting/third-parties/new'));
        self::assertResponseStatusCodeSame(404);

        // The brouillard holds back the bank's draft; its fiche changes it but does not validate it.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/brouillard'));
        self::assertCount(1, $crawler->filter('main input[name="entries[]"][disabled]'));
        self::assertSelectorTextContains('main tbody', 'Hors de votre profil');
        $payment = (string) $crawler->filter('main input[name="entries[]"][disabled]')->attr('value');
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entries/'.$payment));
        self::assertSelectorExists('main [data-confirm-bar-target="actions"] a[href*="/accounting/entry?"]');
        self::assertSelectorNotExists('form[action$="/validate"]');
        $client->request('POST', $this->url($dossier, '/accounting/entries/'.$payment.'/validate'), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTrailIsReadFilteredAndExported(): void
    {
        [$client, $dossier] = $this->group();
        $this->as($client, 'ines.morel');

        $crawler = $client->request('GET', $this->url($dossier, '/audit'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Piste d’audit');
        self::assertSelectorTextContains('.px-moduletabs [aria-current="page"]', 'Piste d’audit');
        $rows = $crawler->filter('main tbody tr');
        self::assertSame('Inès Morel Comptable fournisseurs', self::spaces($rows->first()->filter('td')->eq(2)->text()));
        self::assertSame('Écriture enregistrée au brouillard', trim($rows->first()->filter('td')->eq(3)->text()));

        $crawler = $client->request('GET', $this->url($dossier, '/audit?role=teacher'));
        self::assertSelectorTextContains('main', 'Aucun geste de ce dossier ne répond à ces filtres.');
        $crawler = $client->request('GET', $this->url($dossier, '/audit?role=student&action=accounting.entry_drafted'));
        self::assertCount(2, $crawler->filter('main tbody tr'));
        $crawler = $client->request('GET', $this->url($dossier, '/audit?q=VIR-SCIE'));
        self::assertCount(1, $crawler->filter('main tbody tr'));

        $client->request('GET', $this->url($dossier, '/audit/export.csv?role=student'));
        self::assertResponseIsSuccessful();
        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringContainsString('Date réelle;Date du dossier;Auteur;Rôle;Profil;Action;Détail', $csv);
        self::assertStringContainsString(';15/03/2026;Inès Morel;Étudiant;Comptable fournisseurs;Écriture enregistrée au brouillard;', $csv);
    }

    /** @return array{KernelBrowser, Dossier} */
    private function group(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $martin, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $profiles = ['ines.morel' => DossierProfile::SupplierAccountant, 'theo.garnier' => DossierProfile::CustomerAccountant, 'chloe.fabre' => null];
        foreach (array_keys($profiles) as $login) {
            // The first sign-in places them in BTS CG 1.
            $this->signOut($client);
            $this->submitLogin($client, $login);
        }
        $students = [];
        foreach ($profiles as $login => $profile) {
            $student = $this->account($client, $login);
            \assert($student instanceof User);
            $students[] = [$student, $profile];
        }
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($year instanceof SchoolYear);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $dossier = $this->savedDossier(DossierKind::Group, null, $year);
        foreach ($students as [$student, $profile]) {
            $member = new DossierMember($dossier, $student, new \DateTimeImmutable('2026-11-27 10:00'));
            $member->assignProfile($profile);
            $entityManager->persist($member);
        }
        $entityManager->flush();
        $this->installPlan($dossier);
        $this->prepareLedger($dossier);
        $ines = $students[0][0];
        $ledger = $client->getContainer()->get(Ledger::class);
        $ledger->draft($dossier, new EntryDraft('ACH', new \DateTimeImmutable('2026-03-02'), 'FA-0001', 'Facture Scierie', [
            EntryLineDraft::debit('607000', Money::euros('1000.00')),
            EntryLineDraft::debit('445660', Money::euros('200.00')),
            EntryLineDraft::credit('401000', Money::euros('1200.00'), '', 'F0001'),
        ]), $ines);
        $ledger->draft($dossier, new EntryDraft('BQ', new \DateTimeImmutable('2026-03-05'), 'VIR-SCIE', 'Règlement Scierie', [
            EntryLineDraft::debit('401000', Money::euros('1200.00'), '', 'F0001'),
            EntryLineDraft::credit('512000', Money::euros('1200.00')),
        ]), $ines);
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier];
    }

    private function as(KernelBrowser $client, string $login): void
    {
        $this->signOut($client);
        $this->submitLogin($client, $login);
    }

    private function member(KernelBrowser $client, Dossier $dossier, string $login): DossierMember
    {
        $student = $this->account($client, $login);
        \assert($student instanceof User);
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        $member = $client->getContainer()->get(DossierMemberRepository::class)->findOneFor($dossier, $student);
        \assert($member instanceof DossierMember);

        return $member;
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }

    private static function spaces(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{202F}", "\u{00A0}"], ' ', $text)));
    }
}
