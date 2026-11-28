<?php

declare(strict_types=1);

namespace App\Tests\Controller\Library;

use App\Entity\Accounting\FecImportJob;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Accounting\FecImportJobRepository;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Identity\UserRepository;
use App\Service\Accounting\Fec\Import\FecImport;
use App\Service\Accounting\Fec\Import\FecImportDraft;
use App\Service\Dossier\Library\EmptyTemplateDraft;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bibliothèque › Nouveau dossier modèle › Depuis un FEC through HTTP (steps 1.9b and 1.10b): the
 * third start, the file asked for, a file with an anomaly listed line by line and nothing written,
 * then a template made from a small FEC in the background - its opening, a purchase, its payment
 * lettered - followed on its progress page; a stopped import relaunched, another abandoned.
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
        self::assertCount($before, $dossiers->findAll());

        // A good file.
        $client->request('POST', '/teacher/library/new/fec', ['_token' => $token, ...$this->fields()], ['file' => $this->upload([
            "AN\tÀ-nouveaux\t1\t20260101\t512000\tBanque\t\t\tAN\t20260101\tOuverture\t1000,00\t0,00\t\t\t20260131\t\t",
            "AN\tÀ-nouveaux\t1\t20260101\t401000\tFournisseurs\tSCIE\tScierie\tAN\t20260101\tOuverture\t0,00\t400,00\tA\t20260131\t20260131\t\t",
            "AN\tÀ-nouveaux\t1\t20260101\t101300\tCapital\t\t\tAN\t20260101\tOuverture\t0,00\t600,00\t\t\t20260131\t\t",
            "BQ\tBanque\t1\t20260120\t401000\tFournisseurs\tSCIE\tScierie\tVIR\t20260120\tRèglement\t400,00\t0,00\tA\t20260131\t20260131\t\t",
            "BQ\tBanque\t1\t20260120\t512000\tBanque\t\t\tVIR\t20260120\tRèglement\t0,00\t400,00\t\t\t20260131\t\t",
        ])]);
        // The answer leaves first; the passes run after it (kernel.terminate).
        self::assertResponseStatusCodeSame(303);
        $job = $client->getContainer()->get(FecImportJobRepository::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(FecImportJob::class, $job);
        self::assertResponseRedirects('/teacher/library/imports/'.$job->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Le modèle est prêt');
        self::assertSelectorTextContains('main', 'Ravel, reprise a reçu 2 écritures validées, 2 journaux, 1 tiers, 0 compte ajouté au plan et 1 lettrage.');
        $template = $dossiers->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Dossier::class, $template);
        self::assertSame('Ravel, reprise', $template->getLibraryTitle());
        self::assertFalse($template->isImporting());
        self::assertSelectorExists('main a[href="/teacher/library/'.$template->getId().'"]');
    }

    public function testAStoppedImportIsRelaunchedOrAbandoned(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.durand');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $jobs = $client->getContainer()->get(FecImportJobRepository::class);
        $dossiers = $client->getContainer()->get(DossierRepository::class);
        $before = \count($dossiers->findAll());

        $stopped = [];
        foreach (['Ravel, premier', 'Ravel, second'] as $title) {
            // Launched by the service alone: nothing queues its passes.
            $launched = $client->getContainer()->get(FecImport::class)->launch($this->draft($title), $this->me($client), new \DateTimeImmutable('2026-11-28 10:00'));
            self::assertNotNull($launched);
            $launched->fail('Ligne 4 : une erreur.', new \DateTimeImmutable('2026-11-28 10:01'));
            $entityManager->flush();
            $stopped[] = (int) $launched->getId();
        }
        [$relaunched, $abandoned] = $stopped;

        $crawler = $client->request('GET', '/teacher/library/imports/'.$relaunched);
        self::assertSelectorTextContains('main', 'L’import s’est arrêté');
        self::assertSelectorTextContains('main', 'Ligne 4 : une erreur.');
        $importToken = (string) $crawler->filter('form[action$="/retry"] input[name="_token"]')->attr('value');
        $client->request('POST', '/teacher/library/imports/'.$relaunched.'/retry', ['_token' => $importToken]);
        self::assertResponseRedirects('/teacher/library/imports/'.$relaunched);
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Le modèle est prêt');

        $crawler = $client->request('GET', '/teacher/library/imports/'.$abandoned);
        $template = $jobs->find($abandoned)?->getTemplate();
        self::assertNotNull($template);
        $templateId = $template->getId();
        $importToken = (string) $crawler->filter('form[action$="/abandon"] input[name="_token"]')->attr('value');
        $client->request('POST', '/teacher/library/imports/'.$abandoned.'/abandon', ['_token' => $importToken]);
        self::assertResponseRedirects('/teacher/library/new/fec');
        $entityManager->clear();
        self::assertNull($jobs->find($abandoned));
        self::assertNull($dossiers->find($templateId));
        self::assertCount($before + 1, $dossiers->findAll(), 'The relaunched one is kept, the abandoned one deleted.');
    }

    private function me(KernelBrowser $client): User
    {
        $user = $client->getContainer()->get(UserRepository::class)->findOneBy(['login' => 'prof.durand']);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function draft(string $title): FecImportDraft
    {
        $draft = new FecImportDraft();
        $draft->template = EmptyTemplateDraft::fromRequest(new Request(request: ['title' => $title, ...array_diff_key($this->fields(), ['title' => true])]));
        $draft->fileName = '999123456FEC20261231.txt';
        $draft->bytes = self::HEADER."\r\n".implode("\r\n", [
            "AN\tÀ-nouveaux\t1\t20260101\t512000\tBanque\t\t\tAN\t20260101\tOuverture\t1000,00\t0,00\t\t\t20260131\t\t",
            "AN\tÀ-nouveaux\t1\t20260101\t101300\tCapital\t\t\tAN\t20260101\tOuverture\t0,00\t1000,00\t\t\t20260131\t\t",
        ])."\r\n";

        return $draft;
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
