<?php

declare(strict_types=1);

namespace App\Tests\Controller\Accounting;

use App\Entity\Accounting\EntrySubscription;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntryState;
use App\Enum\Dossier\DossierKind;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Accounting\Entry\PendingEntries;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Subscriptions through HTTP (step 1.4c): the fiche - created, refused, changed, deleted -, the
 * list and « Générer jusqu'au » the dossier's date (15/03/2026): into the brouillard, into the
 * grid's pending work, stopped by a closed month. ines.morel's Menuiserie Vasseur with the rent
 * template (3 200,00 HT at 20 %: 3 840,00 TTC on 401SCIE); the 1st of January, February and March
 * have come on the 15th of March, the 1st of April has not.
 */
final class SubscriptionScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testTheRentIsGeneratedIntoTheBrouillardUpToTheDossiersDate(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/subscriptions'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun abonnement');
        self::assertSelectorExists('button[disabled][title="Aucune écriture d’abonnement n’est due au 15/03/2026."]');

        $token = $this->formToken($client, $dossier, '/accounting/subscriptions/new');
        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/new'), ['_token' => $token, ...$this->rent(['amount' => ''])]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Indiquez le montant HT de chaque écriture.');

        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/new'), ['_token' => $token, ...$this->rent()]);
        $subscription = $this->subscription($client, $dossier);
        self::assertResponseRedirects($this->url($dossier, '/accounting/subscriptions/'.$subscription->getId()));

        $client->request('GET', $this->url($dossier, '/accounting/subscriptions'));
        self::assertSelectorTextContains('main', 'Loyer de l’atelier');
        self::assertSelectorTextContains('main', '3 à générer');
        self::assertSelectorTextContains('#subscriptions-generate-text', 'Générer 3 écritures jusqu’au 15/03/2026 : 3 au brouillard ?');

        $generateToken = $this->generateToken($client, $dossier);
        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/generate'), ['_token' => $generateToken]);
        self::assertResponseRedirects($this->url($dossier, '/accounting/subscriptions'));
        $client->getContainer()->get(DossierContext::class)->enter($dossier);
        $entries = $client->getContainer()->get(EntryRepository::class);
        foreach (['LOY-2026-01' => 'Loyer janvier 2026', 'LOY-2026-02' => 'Loyer février 2026', 'LOY-2026-03' => 'Loyer mars 2026'] as $document => $label) {
            $entry = $entries->findOneBy(['document' => $document]);
            self::assertNotNull($entry, $document);
            self::assertSame(EntryState::Draft, $entry->getState());
            self::assertSame(384_000, $entry->totalDebit()->cents);
            self::assertSame($label, $entry->getLabel());
            self::assertSame('01', $entry->getEntryDate()->format('d'));
        }
        self::assertNull($entries->findOneBy(['document' => 'LOY-2026-04']));
        self::assertSame(3, $this->subscription($client, $dossier)->getGenerated());
        $client->getContainer()->get(DossierContext::class)->leave();

        // Nothing more is due: the gesture makes nothing twice.
        $client->request('GET', $this->url($dossier, '/accounting/subscriptions/'.$subscription->getId()));
        self::assertSelectorCount(3, 'main .px-badge--success');
        self::assertSelectorTextContains('main', '3 générées sur 12');
        self::assertSelectorNotExists('form.px-confirmbar');
        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/generate'), ['_token' => $generateToken]);
        self::assertResponseStatusCodeSame(303);
        self::assertCount(3, $this->entriesOf($client, $dossier));

        // The first month no longer moves; the amount does (a rent raised from April).
        $token = $this->formToken($client, $dossier, '/accounting/subscriptions/'.$subscription->getId());
        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/'.$subscription->getId()), ['_token' => $token, ...$this->rent(['firstMonth' => '2026-02-01'])]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Des écritures sont déjà générées depuis janvier 2026 : le premier mois ne change plus.');
        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/'.$subscription->getId()), ['_token' => $token, ...$this->rent(['amount' => '330000'])]);
        self::assertResponseRedirects();
        self::assertSame(330_000, $this->subscription($client, $dossier)->getAmount()?->cents);

        // The template it calls stays.
        $client->request('GET', $this->url($dossier, '/accounting/templates/LOYER'));
        self::assertSelectorTextContains('main', 'L’abonnement « Loyer de l’atelier » appelle ce modèle : supprimez-le ou donnez-lui un autre modèle d’abord.');
        self::assertSelectorNotExists('#template-delete');

        // Deleting the subscription deletes none of its entries.
        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/'.$subscription->getId().'/delete'), ['_token' => $this->deleteToken($client, $dossier, '/accounting/subscriptions/'.$subscription->getId())]);
        self::assertResponseRedirects($this->url($dossier, '/accounting/subscriptions'));
        self::assertCount(3, $this->entriesOf($client, $dossier));
        $client->request('GET', $this->url($dossier, '/accounting/templates/LOYER'));
        self::assertSelectorExists('#template-delete');
    }

    public function testPendingWorkWaitsInTheGridAndAClosedMonthStopsTheSubscription(): void
    {
        [$client, $dossier] = $this->school();
        $token = $this->formToken($client, $dossier, '/accounting/subscriptions/new');
        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/new'), ['_token' => $token, ...$this->rent(['target' => 'pending'])]);
        self::assertResponseRedirects();

        // February closed: January is made, February stops the subscription, March waits behind it.
        $container = $client->getContainer();
        $container->get(DossierContext::class)->enter($dossier);
        $february = $container->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-02-01'));
        \assert(null !== $february);
        $february->close();
        $container->get(EntityManagerInterface::class)->flush();
        $container->get(DossierContext::class)->leave();

        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/generate'), ['_token' => $this->generateToken($client, $dossier)]);
        $client->followRedirect();
        self::assertSelectorTextContains('main .px-alert--warning', 'Loyer de l’atelier : l’écriture du 01/02/2026 (LOY-2026-02) n’est pas générée, l’abonnement s’arrête à elle. Février 2026 est clos.');
        self::assertSelectorTextContains('main', '1 / 12');
        self::assertSame([], $this->entriesOf($client, $dossier), 'pending work is not in the books');

        $container->get(DossierContext::class)->enter($dossier);
        $ines = $this->account($client, 'ines.morel');
        $journal = $container->get(JournalRepository::class)->findOneByCode($dossier, 'ACH');
        $january = $container->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-01-01'));
        \assert(null !== $ines && null !== $journal && null !== $january);
        $groups = $container->get(PendingEntries::class)->groups($journal, $january, $ines);
        self::assertCount(1, $groups);
        self::assertSame('2026-01-01', $groups[0]->date);
        self::assertSame('LOY-2026-01', $groups[0]->document);
        self::assertSame([320_000, 64_000, 0], array_map(static fn ($line): int => $line->debit, $groups[0]->lines));
        self::assertSame('Loyer janvier 2026', $groups[0]->lines[0]->label);
        $container->get(DossierContext::class)->leave();

        $client->request('GET', $this->url($dossier, '/accounting/entry?journal=ACH&period=2026-01-01'));
        self::assertResponseIsSuccessful();
    }

    public function testAForgedGenerationIsANotFound(): void
    {
        [$client, $dossier] = $this->school();
        $client->request('POST', $this->url($dossier, '/accounting/subscriptions/generate'), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The rent as the fiche posts it: from January, the 1st, twelve times, into the brouillard.
     *
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function rent(array $changes = []): array
    {
        return [...[
            'label' => 'Loyer de l’atelier',
            'template' => 'LOYER',
            'amount' => '320000',
            'rate' => '20',
            'thirdParty' => '',
            'day' => '1',
            'firstMonth' => '2026-01-01',
            'occurrences' => '12',
            'pattern' => 'LOY-{AAAA}-{MM}',
            'target' => 'draft',
        ], ...$changes];
    }

    /** @return array{KernelBrowser, Dossier} the student signed in, the dossier with the rent template */
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

        $token = (string) $client->request('GET', $this->url($dossier, '/accounting/templates/new'))->filter('#template-form input[name="_token"]')->attr('value');
        $client->request('POST', $this->url($dossier, '/accounting/templates/new'), ['_token' => $token, 'code' => 'LOYER', 'label' => 'Loyer de l’atelier', 'journal' => 'ACH', 'base' => 'ht', 'rate' => '20', 'lines' => [
            ['account' => '613200', 'thirdParty' => '', 'label' => 'Loyer {mois}', 'side' => 'debit', 'amount' => 'ht', 'fixed' => ''],
            ['account' => '445660', 'thirdParty' => '', 'label' => 'Loyer {mois}', 'side' => 'debit', 'amount' => 'tva', 'fixed' => ''],
            ['account' => '401SCIE', 'thirdParty' => '', 'label' => 'Loyer {mois}', 'side' => 'credit', 'amount' => 'ttc', 'fixed' => ''],
        ]]);
        self::assertResponseRedirects();

        return [$client, $dossier];
    }

    private function subscription(KernelBrowser $client, Dossier $dossier): EntrySubscription
    {
        $context = $client->getContainer()->get(DossierContext::class);
        $context->enter($dossier);
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        $subscription = $client->getContainer()->get(EntrySubscriptionRepository::class)->findByDossier($dossier)[0] ?? null;
        $context->leave();
        self::assertNotNull($subscription);

        return $subscription;
    }

    /** @return list<\App\Entity\Accounting\Entry> */
    private function entriesOf(KernelBrowser $client, Dossier $dossier): array
    {
        $context = $client->getContainer()->get(DossierContext::class);
        $context->enter($dossier);
        $entries = $client->getContainer()->get(EntryRepository::class)->findBy(['dossier' => $dossier]);
        $context->leave();

        return $entries;
    }

    private function formToken(KernelBrowser $client, Dossier $dossier, string $path): string
    {
        return (string) $client->request('GET', $this->url($dossier, $path))->filter('#subscription-form input[name="_token"]')->attr('value');
    }

    private function deleteToken(KernelBrowser $client, Dossier $dossier, string $path): string
    {
        return (string) $client->request('GET', $this->url($dossier, $path))->filter('#subscription-delete input[name="_token"]')->attr('value');
    }

    private function generateToken(KernelBrowser $client, Dossier $dossier): string
    {
        return (string) $client->request('GET', $this->url($dossier, '/accounting/subscriptions'))->filter('form.px-confirmbar input[name="_token"]')->attr('value');
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
