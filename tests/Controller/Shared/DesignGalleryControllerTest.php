<?php

declare(strict_types=1);

namespace App\Tests\Controller\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The design gallery renders every component in both shells, and its dialog follows the fragment
 * contract: a refusal is the fragment again with a 422, a success a redirect.
 */
final class DesignGalleryControllerTest extends WebTestCase
{
    public function testTheStudentShellGallery(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_design?sort=debit&dir=desc&page=2');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body[data-shell="student"][data-controller="shortcuts"]');
        self::assertSelectorExists('a.px-skip[href="#main"]');
        self::assertSelectorExists('nav.px-rail [aria-current="page"]');
        self::assertSelectorTextContains('.px-pagehead__title', 'Galerie du système de design');
        self::assertSelectorExists('th[aria-sort="descending"]');
        self::assertSelectorExists('.px-pager [aria-current="page"][aria-label="Page 2"]');
        self::assertSelectorExists('.px-statusbar__facts[role="status"]');
        self::assertSelectorExists('#px-shortcuts');
        self::assertCount(11, $crawler->filter('[data-px-rail-entry]'));
    }

    public function testTheRailStateComesFromItsCookie(): void
    {
        $client = self::createClient();
        $client->getCookieJar()->set(new Cookie('px_rail', 'collapsed'));
        $client->request('GET', '/_design');

        self::assertSelectorExists('nav.px-rail.is-collapsed');
    }

    public function testTheTeacherShellGallery(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_design/teacher');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body[data-shell="teacher"]');
        self::assertSelectorExists('.px-actionfoot');
        self::assertSelectorNotExists('.px-statusbar', 'The action foot replaces the status bar.');
    }

    public function testThePublicShellShowsTheErrorPages(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_design/public');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body[data-shell="public"]');
        self::assertSelectorTextContains('h1', 'Page introuvable');
        self::assertSelectorTextContains('.px-public__card', 'Cette page n’existe pas, ou vous n’y avez pas accès.');
        self::assertSelectorExists('a[href="https://github.com/DrakanAethril/Peyra"]');

        $client->request('GET', '/_design/public?code=500');
        self::assertSelectorTextContains('h1', 'L’opération n’a pas abouti');
    }

    public function testTheDialogFragmentRefusesThenRedirects(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_design/dialog');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form textarea#motif');

        $client->request('POST', '/_design/dialog', ['motif' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('textarea#motif[aria-invalid="true"]');
        self::assertSelectorTextContains('.px-field__error', 'Indiquez le motif de la contrepassation.');

        $client->request('POST', '/_design/dialog', ['motif' => 'Erreur de compte']);
        self::assertResponseRedirects('/_design?motif=Erreur%20de%20compte', 303);
    }

    public function testTheSampleDocumentInTheBrowserAndAsAPdf(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_design/document');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.px-docbody.px-docbody--landscape.px-docbody--preview');
        self::assertSelectorTextContains('.px-doc__running h1', 'Balance d’ouverture (extrait)');
        self::assertSelectorTextContains('tfoot', "291\u{202F}293,08");
        self::assertStringNotContainsString('doc-fonts.css', (string) $client->getResponse()->getContent(), 'The preview loads the application\'s fonts.');

        $client->request('GET', '/_design/document.pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }
}
