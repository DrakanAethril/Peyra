<?php

declare(strict_types=1);

namespace App\Tests\Controller\Library;

use App\Entity\Dossier\Dossier;
use App\Repository\Dossier\DossierRepository;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Bibliothèque › Nouveau dossier modèle › Depuis un FEC through HTTP (step 1.9b): the third start,
 * the file asked for, a file with an anomaly listed line by line and nothing written, then a
 * template made from a small FEC - its opening, a purchase, its payment lettered.
 */
final class NewFromFecTest extends WebTestCase
{
    use SignsIn;

    private const string HEADER = "JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise";

    public function testATeacherMakesATemplateFromAFec(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.durand');

        $crawler = $client->request('GET', '/teacher/library/new');
        self::assertSelectorTextContains('main', 'Partir d’un FEC');
        self::assertSelectorExists('main a[href="/teacher/library/new/fec"]');

        $crawler = $client->request('GET', '/teacher/library/new/fec');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#new-template[enctype="multipart/form-data"] input[type="file"][name="file"]');
        self::assertSelectorExists('#denomination');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        // No file.
        $client->request('POST', '/teacher/library/new/fec', ['_token' => $token, ...$this->fields()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#file_error', 'Choisissez le fichier FEC à importer.');

        // A collective line without its auxiliary code: nothing is written, the line is named.
        $dossiers = $client->getContainer()->get(DossierRepository::class);
        $before = \count($dossiers->findAll());
        $client->request('POST', '/teacher/library/new/fec', ['_token' => $token, ...$this->fields()], ['file' => $this->upload([
            "ACH\tAchats\t1\t20260108\t607000\tAchats\t\t\tFA1\t20260108\tFacture\t100,00\t0,00\t\t\t20260131\t\t",
            "ACH\tAchats\t1\t20260108\t401000\tFournisseurs\tSCIE\tScierie\tFA1\t20260108\tFacture\t0,00\t100,00\t\t\t20260131\t\t",
            "ACH\tAchats\t2\t20260110\t607000\tAchats\t\t\tFA2\t20260110\tFacture\t50,00\t0,00\t\t\t20260131\t\t",
            "ACH\tAchats\t2\t20260110\t401000\tFournisseurs\t\t\tFA2\t20260110\tFacture\t0,00\t50,00\t\t\t20260131\t\t",
        ])]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Rien n’est importé');
        self::assertSelectorTextContains('main tr.is-error', 'Les lignes du compte 401000 nomment leur compte auxiliaire');
        self::assertSame('5', trim($client->getCrawler()->filter('main tr.is-error td')->first()->text()));
        self::assertSame($before, \count($dossiers->findAll()));

        // A good file.
        $client->request('POST', '/teacher/library/new/fec', ['_token' => $token, ...$this->fields()], ['file' => $this->upload([
            "AN\tÀ-nouveaux\t1\t20260101\t512000\tBanque\t\t\tAN\t20260101\tOuverture\t1000,00\t0,00\t\t\t20260131\t\t",
            "AN\tÀ-nouveaux\t1\t20260101\t401000\tFournisseurs\tSCIE\tScierie\tAN\t20260101\tOuverture\t0,00\t400,00\tA\t20260131\t20260131\t\t",
            "AN\tÀ-nouveaux\t1\t20260101\t101300\tCapital\t\t\tAN\t20260101\tOuverture\t0,00\t600,00\t\t\t20260131\t\t",
            "BQ\tBanque\t1\t20260120\t401000\tFournisseurs\tSCIE\tScierie\tVIR\t20260120\tRèglement\t400,00\t0,00\tA\t20260131\t20260131\t\t",
            "BQ\tBanque\t1\t20260120\t512000\tBanque\t\t\tVIR\t20260120\tRèglement\t0,00\t400,00\t\t\t20260131\t\t",
        ])]);
        self::assertResponseStatusCodeSame(303);
        $template = $dossiers->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Dossier::class, $template);
        self::assertSame('Ravel, reprise', $template->getLibraryTitle());
        self::assertResponseRedirects('/teacher/library/'.$template->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'créé depuis 999123456FEC20261231.txt : 2 écritures validées, 2 journaux, 1 tiers, 0 compte ajouté au plan, 1 lettrage.');
    }

    /** @return array<string, string> */
    private function fields(): array
    {
        return [
            'title' => 'Ravel, reprise',
            'denomination' => 'Boulangerie Ravel', 'legalForm' => 'sarl', 'capital' => '1000000', 'siret' => '99912345600011',
            'activity' => 'Boulangerie pâtisserie', 'street' => '3 place du Marché', 'postalCode' => '42600', 'city' => 'Montbrison',
            'vatRegime' => 'real_normal_monthly', 'profitTaxRegime' => 'is_real_normal',
            'exerciseStart' => '2026-01-01', 'exerciseEnd' => '2026-12-31', 'dossierDate' => '2026-01-31', 'chart' => 'pcg-2026',
        ];
    }

    /** @param list<string> $lines */
    private function upload(array $lines): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'fec');
        file_put_contents($path, self::HEADER."\r\n".implode("\r\n", $lines)."\r\n");

        return new UploadedFile($path, '999123456FEC20261231.txt', 'text/plain', null, true);
    }
}
