<?php

declare(strict_types=1);

namespace App\Tests\Controller\Library;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DistributionRepository;
use App\Repository\Dossier\DossierRepository;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Distribuer through HTTP (step 1.8c; design-ux.md § 4.2): prof.durand hands the common library's
 * template to BTS CG 1 - the three steps, the récapitulatif, the progress page once the copies are
 * made after the answer -, dates the dossiers, then withdraws the distribution.
 */
final class DistributionScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testATeacherDistributesDatesAndWithdraws(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $this->signOut($client);
        $students = [];
        foreach (['lucas.bernard', 'manon.chevalier', 'ines.morel'] as $login) {
            $this->submitLogin($client, $login);
            $students[$login] = (int) $this->account($client, $login)?->getId();
            $this->signOut($client);
        }
        $this->submitLogin($client, 'prof.durand');
        $template = (int) $this->savedDossier(DossierKind::Template, null)->getId();
        $base = '/teacher/library/'.$template.'/distribute';

        // Step 1: the class's three students, ticked.
        $crawler = $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('#distribute-who input[name="pick[]"][checked]'));
        self::assertSelectorExists('a[href="'.$base.'"]', 'The trail leads back to step 1.');

        // Step 3, a group: one shared dossier for the three.
        $group = ['class' => $cg1->getId(), 'step' => 1, 'pick' => array_values($students), 'mode' => 'group'];
        $client->request('GET', $base.'/summary?'.http_build_query($group));
        self::assertSelectorTextContains('main', '1 dossier partagé sera créé depuis « Menuiserie Vasseur SARL » pour 3 étudiants, à la date du 15/03/2026.');

        // Step 3, two students, each their own, from the 01/04/2026.
        $two = ['class' => $cg1->getId(), 'step' => 1, 'pick' => [$students['lucas.bernard'], $students['manon.chevalier']], 'mode' => 'individual', 'start' => '2026-04-01'];
        $crawler = $client->request('GET', $base.'/summary?'.http_build_query($two));
        self::assertSelectorTextContains('main', '2 dossiers seront créés depuis « Menuiserie Vasseur SARL », un par étudiant, à la date du 01/04/2026.');
        $form = $crawler->filter('form[action="'.$base.'"]');
        $token = (string) $form->filter('input[name="_token"]')->attr('value');

        $client->request('POST', $base, ['_token' => 'forged'] + $two);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $base, ['_token' => $token, 'class' => $cg1->getId(), 'pick' => [$students['lucas.bernard']], 'mode' => 'group']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Le groupe 1 n’a qu’un membre');

        $client->request('POST', $base, ['_token' => $token] + $two);
        self::assertResponseStatusCodeSame(303);
        $distribution = $client->getContainer()->get(DistributionRepository::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($distribution);
        $page = '/teacher/library/distributions/'.$distribution->getId();
        self::assertResponseRedirects($page);
        // The copies were made once the answer had left (kernel.terminate).
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Distribution terminée · 2 dossiers prêts sur 2');
        self::assertCount(2, $crawler->filter('main a.px-btn')->reduce(static fn ($link): bool => str_starts_with((string) $link->attr('href'), '/d/')));
        $lucas = $client->getContainer()->get(DossierRepository::class)->findForStudent($this->account($client, 'lucas.bernard') ?? throw new \LogicException('Signed in.'));
        self::assertCount(1, $lucas);
        self::assertSame('2026-04-01', $lucas[0]->getDossierDate()->format('Y-m-d'));

        // The date of both dossiers: a teacher of their class moves it (DossierVoter::SET_DATE).
        $token = (string) $crawler->filter('form[action="'.$page.'/date"] input[name="_token"]')->attr('value');
        $client->request('POST', $page.'/date', ['_token' => $token, 'date' => '2026-05-01']);
        self::assertResponseRedirects($page);
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '0 dossier daté au 01/05/2026 · 2 non changés : vous n’enseignez pas dans la classe de leurs étudiants cette année.');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TeacherClass($this->account($client, 'prof.durand') ?? throw new \LogicException('Signed in.'), $entityManager->find(SchoolClass::class, $cg1->getId()) ?? throw new \LogicException('Built.'), new \DateTimeImmutable('2026-11-28'), null));
        $entityManager->flush();
        $client->request('POST', $page.'/date', ['_token' => $token, 'date' => '2026-05-01']);
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '2 dossiers datés au 01/05/2026.');

        // The list, then the withdrawal.
        $client->request('GET', '/teacher/library/distributions');
        self::assertSelectorTextContains('main', 'Menuiserie Vasseur SARL');
        $crawler = $client->request('GET', $page.'/withdraw');
        self::assertSelectorTextContains('main', 'Les 2 dossiers de la distribution en BTS CG 1');
        $token = (string) $crawler->filter('#distribution-withdraw input[name="_token"]')->attr('value');
        $client->request('POST', $page.'/withdraw', ['_token' => $token, 'confirmTitle' => 'Vasseur']);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $page.'/withdraw', ['_token' => $token, 'confirmTitle' => 'Menuiserie Vasseur SARL']);
        self::assertResponseRedirects('/teacher/library/distributions');
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Aucune distribution');
        self::assertSame([], $client->getContainer()->get(DossierRepository::class)->findForStudent($this->account($client, 'lucas.bernard') ?? throw new \LogicException('Signed in.')));
    }
}
