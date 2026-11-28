<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Mission\MissionAnswerKey;
use App\Enum\Dossier\DossierKind;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Mission\MissionAnswerKeyRepository;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Dossier\DossierContext;
use App\Service\Mission\MissionSheet;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Mission › Corrigé (step 2.9b): prof.durand plays his mission in a copy of its template (kind
 * AnswerKey, his alone), validates the draft the template held and writes a purchase, records it -
 * the two entries are expected, the template's own entries are not -, then sets the tolerances; a
 * date window out of bounds is refused. Inès reaches neither the tab nor the copy (404).
 */
final class MissionAnswerKeyTest extends WebTestCase
{
    use BuildsLedger;
    use SignsIn;

    public function testATeacherRecordsTheAnswerKeyAndItsTolerances(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'ines.morel');
        $this->signOut($client);
        $this->submitLogin($client, 'prof.durand');
        $durand = $this->account($client, 'prof.durand');
        \assert($durand instanceof User);
        $template = $this->savedDossier(DossierKind::Template, $durand);
        $this->installPlan($template);
        $this->prepareLedger($template);
        $this->entry($template, 'AN', '2026-01-01', 'AN-2026', ['512000 D 5000', '101300 C 5000'], true);
        $this->entry($template, 'ACH', '2026-03-01', 'LOY-2026-03', ['613200 D 3200', '445660 D 640', '401000/F0001 C 3840']);
        $mission = $client->getContainer()->get(MissionSheet::class)->create($template, 'Mars', $durand, new \DateTimeImmutable('2026-11-28'));
        $client->getContainer()->get(DossierContext::class)->reset();
        $base = '/teacher/library/missions/'.$mission->getId().'/answer-key';

        $crawler = $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form[action$="/play"] input[name="_token"]')->attr('value');
        $client->request('POST', $base.'/play', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $copy = $client->getContainer()->get(MissionAnswerKeyRepository::class)->findOneByMission($mission)?->getDossier();
        self::assertInstanceOf(Dossier::class, $copy);
        self::assertSame([DossierKind::AnswerKey, $durand->getId()], [$copy->getKind(), $copy->getOwner()?->getId()]);
        self::assertResponseRedirects('/d/'.$copy->getId(), 303);
        $client->request('GET', '/d/'.$copy->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-contextbar', 'Corrigé de la mission « Mars »');

        // Played: the template's draft validated, a purchase written.
        $container = $client->getContainer();
        $copy = $container->get(EntityManagerInterface::class)->find(Dossier::class, $copy->getId());
        \assert($copy instanceof Dossier);
        $container->get(DossierContext::class)->within($copy, function () use ($container, $copy): void {
            $container->get(Ledger::class)->validate($container->get(EntryRepository::class)->findDrafts(), 'test');
            $this->entry($copy, 'ACH', '2026-03-20', 'FA-2603-0112', ['601000 D 2707', '445660 D 541.40', '401000/F0001 C 3248.40']);
        });
        $client->request('POST', $base.'/record', ['_token' => $token]);
        self::assertResponseRedirects($base, 303);

        $answerKey = $container->get(MissionAnswerKeyRepository::class)->findOneBy(['dossier' => $copy]);
        self::assertInstanceOf(MissionAnswerKey::class, $answerKey);
        self::assertSame([['ACH', 'LOY-2026-03', 'validated'], ['ACH', 'FA-2603-0112', 'draft']], array_map(static fn (array $entry): array => [$entry['journal'], $entry['document'], $entry['state']], $answerKey->getEntries()));
        self::assertSame([['601000', null, 270700, 0], ['445660', null, 54140, 0], ['401000', 'F0001', 0, 324840]], array_map(static fn (array $line): array => [$line['account'], $line['thirdParty'], $line['debit'], $line['credit']], $answerKey->getEntries()[1]['lines']));

        $client->request('POST', $base, ['_token' => $token, 'equivalentAccounts' => '601000 607000', 'dateWindow' => '40', 'optional' => ['1.1']]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $base, ['_token' => $token, 'equivalentAccounts' => "601000 607000\r\n", 'dateWindow' => '3', 'optional' => ['1.1']]);
        self::assertResponseRedirects($base, 303);
        $container->get(EntityManagerInterface::class)->clear();
        $answerKey = $container->get(MissionAnswerKeyRepository::class)->find($answerKey->getId());
        self::assertInstanceOf(MissionAnswerKey::class, $answerKey);
        self::assertSame([[['601000', '607000']], 3], [$answerKey->getEquivalentAccounts(), $answerKey->getDateWindowDays()]);
        self::assertSame([false, true, false], array_column($answerKey->getEntries()[1]['lines'], 'optional'));
        $crawler = $client->request('GET', $base);
        self::assertCount(1, $crawler->filter('input[name="optional[]"][checked]'));

        $copyId = (int) $copy->getId();
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        foreach ([$base, '/d/'.$copyId] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }
}
