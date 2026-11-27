<?php

declare(strict_types=1);

namespace App\Tests\Controller\Identity;

use App\Entity\Identity\AuthorizedTeacher;
use App\Entity\Identity\LoginEvent;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Identity\LoginOutcome;
use App\Repository\Identity\LoginEventRepository;
use App\Security\SignIn;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The six entry cases of step 0.4, through the real sign-in form, plus what surrounds them: the
 * same answer for a wrong password and an unknown login, a renamed login, the throttle, the
 * journal. The directory is the in-memory one (tests/Support/InMemoryDirectory).
 */
final class SignInTest extends WebTestCase
{
    use SignsIn;

    private const string NOT_IN_PROGRAMME = 'Peyra est réservé aux étudiants et aux enseignants du BTS CG. Votre compte n’en fait pas partie.';
    private const string TEACHER_NOT_AUTHORIZED = 'Votre compte n’est pas encore autorisé dans Peyra. Demandez à un administrateur de vous ajouter aux enseignants de la filière.';

    public function testACgStudentEntersOnTheirDossiers(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'ines.morel');

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseRedirects('/dossiers');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Mes dossiers');
        self::assertSelectorTextContains('.px-empty__title', 'Aucun dossier pour l’instant');

        $account = $this->account($client, 'ines.morel');
        self::assertNotNull($account);
        self::assertSame(AccountKind::Student, $account->getKind());
        self::assertSame('Inès Morel', $account->getDisplayName());
        self::assertSame('entryUUID:test-40104', $account->getDirectoryId());
        self::assertSame(['ROLE_USER', 'ROLE_STUDENT'], $account->getRoles());
    }

    public function testAStudentOutsideTheCgGroupsIsRefusedAndGetsNoAccount(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'enzo.petit');

        self::assertSame(self::NOT_IN_PROGRAMME, $this->loginError($client));
        self::assertNull($this->account($client, 'enzo.petit'));
        $client->request('GET', '/dossiers');
        self::assertResponseRedirects('/login');
    }

    public function testACgTeacherEntersTheTeacherSpace(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'prof.durand');
        $client->followRedirect();

        self::assertResponseRedirects('/teacher');
        $client->followRedirect();
        self::assertSelectorTextContains('.px-chip', 'Espace enseignant');
        $account = $this->account($client, 'prof.durand');
        self::assertSame(EntryBasis::TeacherGroup, $account?->getEntryBasis());
        self::assertFalse($account->isAdministrator());
    }

    public function testATeacherOutsideTheProgrammeIsRefusedWithTheirOwnMessage(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'prof.leroy');

        self::assertSame(self::TEACHER_NOT_AUTHORIZED, $this->loginError($client));
        self::assertNull($this->account($client, 'prof.leroy'));
    }

    public function testALoginOfTheBootstrapListEntersAsAdministratorWithoutACgGroup(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        // prof.martin (PEYRA_ADMIN_LOGINS) leaves the CG groups: the bootstrap list still lets him in.
        $this->directory($client)->add('prof.martin', 'Jean-Pierre Martin', ['teacher', 'sio-1'], 'entryUUID:test-30001');
        $this->submitLogin($client, 'prof.martin');

        self::assertResponseRedirects('/');
        $account = $this->account($client, 'prof.martin');
        self::assertSame(EntryBasis::BootstrapList, $account?->getEntryBasis());
        self::assertTrue($account->isAdministrator());
        self::assertNull($account->getAdministratorDesignatedBy());
        self::assertContains('ROLE_ADMIN', $account->getRoles());
    }

    public function testATeacherAuthorisedByNameEntersAndIsSignedOutOnceRemoved(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $client->request('POST', '/teacher/admin/teachers/add', [
            '_token' => $this->tokenOf($client, '/teacher/admin/teachers', 'form[action$="/add"] input[name="_token"]'),
            'login' => 'prof.leroy',
        ]);
        self::assertResponseRedirects('/teacher/admin/teachers');
        $this->signOut($client);
        $this->submitLogin($client, 'prof.leroy');
        self::assertResponseRedirects('/');
        $leroy = $this->account($client, 'prof.leroy');
        self::assertSame(EntryBasis::NamedAuthorization, $leroy?->getEntryBasis());
        $client->request('GET', '/teacher');
        self::assertResponseIsSuccessful();

        // An administrator removes him: his open session ends at his next request.
        $authorization = $client->getContainer()->get('doctrine')->getRepository(AuthorizedTeacher::class)->findOneBy(['login' => 'prof.leroy']);
        self::assertNotNull($authorization);
        $martin = $this->account($client, 'prof.martin');
        self::assertNotNull($martin);
        $authorization->revoke($martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get('doctrine')->getManager()->flush();

        $client->request('GET', '/teacher');
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        $this->submitLogin($client, 'prof.leroy');
        self::assertSame(self::TEACHER_NOT_AUTHORIZED, $this->loginError($client));
    }

    public function testAWrongPasswordAndAnUnknownLoginReadTheSame(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'ines.morel', 'not-the-password');
        self::assertSame(SignIn::BAD_CREDENTIALS, $this->loginError($client));
        self::assertSelectorExists('input[name="_username"][value="ines.morel"]');

        $this->submitLogin($client, 'nobody.here');
        self::assertSame(SignIn::BAD_CREDENTIALS, $this->loginError($client));
        // A refused person learns nothing before the password is right.
        $this->submitLogin($client, 'enzo.petit', 'wrong');
        self::assertSame(SignIn::BAD_CREDENTIALS, $this->loginError($client));
    }

    public function testARenamedLoginKeepsItsAccount(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'lucas.bernard');
        $before = $this->account($client, 'lucas.bernard');
        self::assertNotNull($before);
        $id = $before->getId();
        $this->signOut($client);

        $this->directory($client)->rename('lucas.bernard', 'lucas.bernard2');
        $this->submitLogin($client, 'lucas.bernard2');

        self::assertResponseRedirects('/');
        $after = $this->account($client, 'lucas.bernard2');
        self::assertSame($id, $after?->getId());
        self::assertSame('entryUUID:test-40101', $after->getDirectoryId());
        self::assertNull($this->account($client, 'lucas.bernard'));
    }

    public function testTooManyAttemptsOnALoginAreStopped(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        for ($attempt = 1; $attempt <= 8; ++$attempt) {
            $this->submitLogin($client, 'theo.garnier', 'wrong-'.$attempt);
            self::assertSame(SignIn::BAD_CREDENTIALS, $this->loginError($client));
        }
        // Even the right password waits now.
        $this->submitLogin($client, 'theo.garnier');

        self::assertStringStartsWith('Trop de tentatives. Réessayez dans', (string) $this->loginError($client));
        self::assertNull($this->account($client, 'theo.garnier'));
        self::assertSame(LoginOutcome::Throttled, $this->lastEvent($client)?->getOutcome());
    }

    public function testADirectoryThatDoesNotAnswerIsSaidSo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->directory($client)->takeDown();
        $this->submitLogin($client, 'ines.morel');

        self::assertSame(SignIn::DIRECTORY_DOWN, $this->loginError($client));
    }

    public function testAnExpiredFormIsRefused(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $client->request('POST', '/login', ['_username' => 'ines.morel', '_password' => 'peyra-dev', '_csrf_token' => 'forged']);

        self::assertSame('La page a expiré. Saisissez de nouveau votre mot de passe.', $this->loginError($client));
    }

    public function testEveryAttemptIsJournaled(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'enzo.petit');
        $refused = $this->lastEvent($client);
        self::assertSame(LoginOutcome::RefusedNotInProgramme, $refused?->getOutcome());
        self::assertSame('Enzo Petit', $refused->getDisplayName());
        self::assertNull($refused->getUser());

        $this->submitLogin($client, 'chloe.fabre');
        $success = $this->lastEvent($client);
        self::assertSame(LoginOutcome::Success, $success?->getOutcome());
        self::assertSame('chloe.fabre', $success->getUser()?->getLogin());
        self::assertSame('127.0.0.1', $success->getIp());
    }

    public function testSigningOutEndsTheSession(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'ines.morel');
        $client->request('GET', '/dossiers');
        $logout = $client->getCrawler()->filter('a[href^="/logout"]')->attr('href');
        self::assertNotNull($logout);
        $client->request('GET', $logout);
        self::assertResponseRedirects('/login');

        $client->request('GET', '/dossiers');
        self::assertResponseRedirects('/login');
    }

    public function testASignedInPersonIsSentHomeFromTheSignInPage(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'ines.morel');
        $client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testTheDevelopmentRouteSignsInWithoutPasswordButKeepsTheRule(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_dev/login-as/enzo.petit');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/_dev/login-as/prof.durand');
        self::assertResponseRedirects('/');
        $client->request('GET', '/teacher');
        self::assertResponseIsSuccessful();
    }

    private function lastEvent(KernelBrowser $client): ?LoginEvent
    {
        return $client->getContainer()->get(LoginEventRepository::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function tokenOf(KernelBrowser $client, string $page, string $selector): string
    {
        $client->request('GET', $page);

        return (string) $client->getCrawler()->filter($selector)->attr('value');
    }
}
