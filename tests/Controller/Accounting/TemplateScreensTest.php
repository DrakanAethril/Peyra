<?php

declare(strict_types=1);

namespace App\Tests\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntryState;
use App\Enum\Dossier\DossierKind;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Entry templates through HTTP (step 1.4b): the fiche - created, refused with its faults, changed,
 * deleted -, F6 in the grid (the lines of a call), and the guided entry - hidden from a student at
 * the default level, open to the teacher, its preview, F10 to the brouillard.
 * ines.morel's Menuiserie Vasseur; the rent is 3 200,00 HT, 640,00 of VAT at 20 %, 3 840,00 TTC.
 */
final class TemplateScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testAStudentWritesChangesAndDeletesATemplate(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/templates'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun modèle d’écriture');

        $token = $this->formToken($client, $dossier, '/accounting/templates/new');
        $client->request('POST', $this->url($dossier, '/accounting/templates/new'), ['_token' => $token, ...$this->rent()]);
        self::assertResponseRedirects($this->url($dossier, '/accounting/templates'));
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Loyer de l’atelier');
        self::assertSelectorTextContains('main', 'Montant HT · TVA 20');

        $token = $this->formToken($client, $dossier, '/accounting/templates/LOYER');
        $changed = $this->rent();
        $changed['rate'] = '5,5';
        $client->request('POST', $this->url($dossier, '/accounting/templates/LOYER'), ['_token' => $token, ...$changed]);
        self::assertResponseRedirects($this->url($dossier, '/accounting/templates/LOYER'));
        $client->getContainer()->get(DossierContext::class)->enter($dossier);
        self::assertSame('5.5', $client->getContainer()->get(EntryTemplateRepository::class)->findOneByCode($dossier, 'LOYER')?->getVatRate());
        $client->getContainer()->get(DossierContext::class)->leave();

        $refused = $this->rent(withVat: false);
        $client->request('POST', $this->url($dossier, '/accounting/templates/LOYER'), ['_token' => $token, ...$refused]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Les lignes ne s’équilibrent pas');

        $client->request('GET', $this->url($dossier, '/accounting/journals/ACH'));
        self::assertSelectorTextContains('main', 'Ce journal porte un modèle d’écriture : supprimez-le ou placez-le dans un autre journal d’abord.');
        self::assertSelectorNotExists('#journal-delete');

        $client->request('POST', $this->url($dossier, '/accounting/templates/LOYER/delete'), ['_token' => $this->deleteToken($client, $dossier, '/accounting/templates/LOYER', 'template-delete')]);
        self::assertResponseRedirects($this->url($dossier, '/accounting/templates'));
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Aucun modèle d’écriture');
    }

    public function testF6CallsATemplateOfTheJournal(): void
    {
        [$client, $dossier] = $this->school();
        $token = $this->formToken($client, $dossier, '/accounting/templates/new');
        $client->request('POST', $this->url($dossier, '/accounting/templates/new'), ['_token' => $token, ...$this->rent()]);

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry?journal=ACH&period=2026-02-01'));
        $grid = json_decode((string) $crawler->filter('[data-controller="entry-grid"]')->attr('data-entry-grid-grid-value'), true);
        self::assertIsArray($grid);
        self::assertSame([['code' => 'LOYER', 'label' => 'Loyer de l’atelier', 'asks' => true, 'base' => 'Montant HT', 'rate' => '20', 'party' => false]], $grid['templates']);
        self::assertSelectorExists('#entry-template[data-px-shortcut="F6"]');
        $gridToken = (string) $crawler->filter('[data-controller="entry-grid"]')->attr('data-entry-grid-token-value');

        $call = $this->post($client, $dossier, $gridToken, ['template' => 'LOYER', 'date' => '2026-02-01', 'amount' => 320_000, 'rate' => '20']);
        self::assertResponseIsSuccessful();
        self::assertSame([
            ['account' => '613200', 'thirdParty' => '', 'label' => 'Loyer février 2026', 'debit' => 320_000, 'credit' => 0, 'due' => null],
            ['account' => '445660', 'thirdParty' => '', 'label' => 'Loyer février 2026', 'debit' => 64_000, 'credit' => 0, 'due' => null],
            ['account' => '401SCIE', 'thirdParty' => '', 'label' => 'Loyer février 2026', 'debit' => 0, 'credit' => 384_000, 'due' => null],
        ], $call['lines']);

        $missing = $this->post($client, $dossier, $gridToken, ['template' => 'LOYER', 'date' => '2026-02-01', 'amount' => null, 'rate' => '20']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['amount' => 'Indiquez le montant HT.'], $missing['errors']);

        $this->post($client, $dossier, 'forged', ['template' => 'LOYER', 'date' => '2026-02-01', 'amount' => 1, 'rate' => '20']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheGuidedEntryIsHiddenFromAStudentAtTheDefaultLevel(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/entry/guided'));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $this->url($dossier, '/accounting/entry?journal=ACH&period=2026-02-01'));
        self::assertSelectorNotExists('a[href$="/accounting/entry/guided"]');

        // At G and B (a mission's level, step 2.9) the same screen opens: GuidedEntry::isOpen(), tested apart.
    }

    public function testTheTeacherWritesAnEntryThroughTheGuidedEntry(): void
    {
        [$client, $dossier] = $this->school();
        $token = $this->formToken($client, $dossier, '/accounting/templates/new');
        $client->request('POST', $this->url($dossier, '/accounting/templates/new'), ['_token' => $token, ...$this->rent()]);
        $this->signOut($client);
        $this->submitLogin($client, 'prof.martin');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry/guided?template=LOYER&date=2026-02-01&document=LOY-2026-02'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'L’écriture se calcule à mesure');
        $guidedToken = (string) $crawler->filter('#guided-form input[name="_token"]')->attr('value');

        $client->request('GET', $this->url($dossier, '/accounting/entry/guided/preview?template=LOYER&date=2026-02-01&document=LOY-2026-02&amount=320000&rate=20'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#guided-preview', 'Loyer février 2026');
        self::assertSelectorTextContains('turbo-frame#guided-preview', 'Équilibrée');

        $client->request('POST', $this->url($dossier, '/accounting/entry/guided'), ['_token' => $guidedToken, 'template' => 'LOYER', 'date' => '2026-02-01', 'document' => 'LOY-2026-02', 'amount' => '', 'rate' => '20']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Indiquez le montant HT.');

        $client->request('POST', $this->url($dossier, '/accounting/entry/guided'), ['_token' => $guidedToken, 'template' => 'LOYER', 'date' => '2026-02-01', 'document' => 'LOY-2026-02', 'amount' => '320000', 'rate' => '20']);
        self::assertResponseRedirects($this->url($dossier, '/accounting/entry/guided?template=LOYER&date=2026-02-01&document=LOY-2026-03'));
        $client->getContainer()->get(DossierContext::class)->enter($dossier);
        $written = $client->getContainer()->get(EntryRepository::class)->findOneBy(['document' => 'LOY-2026-02']);
        self::assertNotNull($written);
        self::assertSame(EntryState::Draft, $written->getState());
        self::assertSame(384_000, $written->totalDebit()->cents);
        self::assertSame('Loyer février 2026', $written->getLabel());
    }

    /** @return array<string, mixed> the rent as the fiche posts it, with a spare empty line (without its VAT line, it does not balance) */
    private function rent(bool $withVat = true): array
    {
        $rent = [
            'code' => 'loyer',
            'label' => 'Loyer de l’atelier',
            'journal' => 'ACH',
            'base' => 'ht',
            'rate' => '20',
            'lines' => [
                0 => ['account' => '613200', 'thirdParty' => '', 'label' => 'Loyer {mois}', 'side' => 'debit', 'amount' => 'ht', 'fixed' => ''],
                1 => ['account' => '445660', 'thirdParty' => '', 'label' => 'Loyer {mois}', 'side' => 'debit', 'amount' => 'tva', 'fixed' => ''],
                2 => ['account' => '401scie', 'thirdParty' => '', 'label' => 'Loyer {mois}', 'side' => 'credit', 'amount' => 'ttc', 'fixed' => ''],
                7 => ['account' => '', 'thirdParty' => '', 'label' => '', 'side' => 'debit', 'amount' => 'typed', 'fixed' => ''],
            ],
        ];
        if (!$withVat) {
            unset($rent['lines'][1]);
        }

        return $rent;
    }

    /** @return array{KernelBrowser, Dossier} */
    private function school(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $martin, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($ines instanceof User && $year instanceof SchoolYear);
        $dossier = $this->savedDossier(DossierKind::Student, $ines, $year);
        $this->installPlan($dossier);
        $this->prepareLedger($dossier);
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier];
    }

    private function formToken(KernelBrowser $client, Dossier $dossier, string $path): string
    {
        $client->getContainer()->get(DossierContext::class)->leave();

        return (string) $client->request('GET', $this->url($dossier, $path))->filter('#template-form input[name="_token"]')->attr('value');
    }

    private function deleteToken(KernelBrowser $client, Dossier $dossier, string $path, string $form): string
    {
        $client->getContainer()->get(DossierContext::class)->leave();

        return (string) $client->request('GET', $this->url($dossier, $path))->filter('#'.$form.' input[name="_token"]')->attr('value');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, Dossier $dossier, string $token, array $body): array
    {
        $client->request('POST', $this->url($dossier, '/accounting/entry/template'), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PEYRA_TOKEN' => $token], content: (string) json_encode(['journal' => 'ACH', 'period' => '2026-02-01', ...$body]));
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
