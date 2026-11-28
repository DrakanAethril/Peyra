<?php

declare(strict_types=1);

namespace App\Tests\Controller\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Dossier\DossierShareRepository;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bibliothèque › Dossiers modèles through HTTP (step 1.8a): prof.durand makes an empty template,
 * describes it, duplicates the common library's, shares his own with prof.martin, who then reads
 * it among « Partagés avec moi » and may neither share nor delete it; a deletion asks for the
 * template's title.
 */
final class LibraryScreensTest extends WebTestCase
{
    use SavesDossiers;
    use SignsIn;

    public function testATeacherMakesDescribesDuplicatesSharesAndDeletesTemplates(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $this->signOut($client);
        $this->submitLogin($client, 'prof.durand');
        $crawler = $client->request('GET', '/teacher/library');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun dossier modèle pour l’instant');

        // An empty template: refused first, then made with its plan and its five journals.
        $crawler = $client->request('GET', '/teacher/library/new/empty');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/teacher/library/new/empty', ['_token' => $token, 'title' => 'Ravel', 'exerciseStart' => '2026-01-01', 'exerciseEnd' => '2025-12-31', 'dossierDate' => '2026-01-01', 'chart' => 'pcg-2026']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#denomination_error', 'Indiquez la dénomination');
        self::assertSelectorTextContains('#exerciseEnd_error', 'L’exercice finit après son premier jour.');
        $client->request('POST', '/teacher/library/new/empty', ['_token' => $token, ...$this->company(), 'title' => 'Ravel, création', 'exerciseStart' => '2026-01-01', 'exerciseEnd' => '2026-12-31', 'dossierDate' => '2026-01-01', 'chart' => 'pcg-2026']);
        self::assertResponseStatusCodeSame(303);
        $ravel = $this->dossiers($client)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Dossier::class, $ravel);
        self::assertResponseRedirects('/teacher/library/'.$ravel->getId());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Ravel, création');
        self::assertSelectorTextContains('.px-statusbar', 'cinq journaux sont prêts');
        self::assertStringContainsString('5 journaux', $crawler->filter('main')->text());

        // Its card.
        $token = (string) $crawler->filter('#template-card input[name="_token"]')->attr('value');
        $client->request('POST', '/teacher/library/'.$ravel->getId(), ['_token' => 'forged', 'title' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/teacher/library/'.$ravel->getId(), ['_token' => $token, 'title' => 'Ravel, création', 'level' => 'second', 'processes' => ['P3', 'P1'], 'description' => 'Une boulangerie.']);
        self::assertResponseRedirects('/teacher/library/'.$ravel->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Fiche du modèle enregistrée.');

        // The common library's template, duplicated into his own.
        $common = $this->savedDossier(DossierKind::Template, null, null, 'Menuiserie Vasseur');
        $crawler = $client->request('GET', '/teacher/library/'.$common->getId());
        self::assertSelectorNotExists('#template-card', 'The common library is read, not changed, by a teacher.');
        self::assertSelectorNotExists('#partages');
        $token = (string) $crawler->filter('#template-duplicate input[name="_token"]')->attr('value');
        $client->request('POST', '/teacher/library/'.$common->getId().'/duplicate', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Menuiserie Vasseur SARL (copie)');
        $crawler = $client->request('GET', '/teacher/library');
        self::assertSame(['Tous · 3', 'Mes modèles · 2', 'Partagés avec moi · 0', 'Fournis · 1'], $crawler->filter('.px-filters__chip')->each(static fn ($chip): string => trim(preg_replace('/\s+/u', ' ', $chip->text()) ?? '')));
        self::assertCount(1, $client->request('GET', '/teacher/library?q=boulangerie')->filter('.px-templatecard'));

        // Shared with prof.martin, for change.
        $client->request('GET', '/teacher/library/'.$ravel->getId().'/colleagues?q=mar');
        self::assertSame('prof.martin', $this->json($client)[0]['login'] ?? null);
        $crawler = $client->request('GET', '/teacher/library/'.$ravel->getId());
        $token = (string) $crawler->filter('#partages form input[name="_token"]')->last()->attr('value');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        $client->request('POST', '/teacher/library/'.$ravel->getId().'/shares', ['_token' => $token, 'colleague' => '', 'colleague_label' => 'zz']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#colleague_error', 'Choisissez un enseignant dans la liste.');
        $client->request('POST', '/teacher/library/'.$ravel->getId().'/shares', ['_token' => $token, 'colleague' => (string) $martin->getId(), 'right' => 'edit']);
        self::assertResponseRedirects('/teacher/library/'.$ravel->getId().'#partages');
        self::assertNotNull($client->getContainer()->get(DossierShareRepository::class)->findOneFor($ravel, $martin));

        $this->signOut($client);
        $this->submitLogin($client, 'prof.martin');
        $crawler = $client->request('GET', '/teacher/library?shelf=shared');
        self::assertSame(['Ravel, création'], $crawler->filter('.px-templatecard h2')->each(static fn ($title): string => trim($title->text())));
        $crawler = $client->request('GET', '/teacher/library/'.$ravel->getId());
        self::assertSelectorExists('#template-card', 'Shared for change: he changes its card.');
        self::assertSelectorNotExists('#partages');
        $client->request('GET', '/teacher/library/'.$ravel->getId().'/delete');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/teacher/library/'.$ravel->getId().'/shares', ['_token' => $token, 'colleague' => '1']);
        self::assertResponseStatusCodeSame(404);

        // Deleted by its author, once its title is typed.
        $this->signOut($client);
        $this->submitLogin($client, 'prof.durand');
        $crawler = $client->request('GET', '/teacher/library/'.$ravel->getId().'/delete');
        self::assertSelectorTextContains('main', 'Ses partages : 1 collègue ne le lira plus.');
        $token = (string) $crawler->filter('#template-delete input[name="_token"]')->attr('value');
        $client->request('POST', '/teacher/library/'.$ravel->getId().'/delete', ['_token' => $token, 'confirmTitle' => 'Ravel']);
        self::assertResponseStatusCodeSame(422);
        $id = $ravel->getId();
        $client->request('POST', '/teacher/library/'.$id.'/delete', ['_token' => $token, 'confirmTitle' => 'Ravel, création']);
        self::assertResponseRedirects('/teacher/library');
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull($this->dossiers($client)->find((int) $id));
    }

    /** @return array<string, string> the company's fields of the form */
    private function company(): array
    {
        return [
            'denomination' => 'Boulangerie Ravel', 'legalForm' => 'sarl', 'capital' => '1000000', 'siret' => '99912345600011',
            'activity' => 'Boulangerie pâtisserie', 'street' => '3 place du Marché', 'postalCode' => '42600', 'city' => 'Montbrison',
            'vatRegime' => 'real_normal_monthly', 'profitTaxRegime' => 'is_real_normal',
        ];
    }

    private function dossiers(KernelBrowser $client): DossierRepository
    {
        return $client->getContainer()->get(DossierRepository::class);
    }

    /** @return list<array<string, mixed>> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return array_values(array_filter($decoded, is_array(...)));
    }
}
