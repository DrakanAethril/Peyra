<?php

declare(strict_types=1);

namespace App\Tests\Command\Identity;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:ldap:check` asks the real directory - the development one here, which CI starts too - and
 * says how Peyra reads a person, without printing anything secret.
 */
final class LdapCheckCommandTest extends KernelTestCase
{
    public function testSaysTheDirectoryAnswersAndHowAPersonIsRead(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:ldap:check'));

        self::assertSame(0, $tester->execute(['login' => 'ines.morel']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('L’annuaire répond et le compte de service se lie.', $display);
        self::assertStringContainsString('uid=ines.morel,ou=users,dc=peyra,dc=lan', $display);
        self::assertStringContainsString('lu dans entryUUID', $display);
        self::assertStringContainsString('Morel Inès', $display);
        self::assertStringContainsString('cg-1, student', $display);
        self::assertStringContainsString('entre comme étudiant (Groupe de l’annuaire)', $display);
        self::assertStringNotContainsString((string) getenv('LDAP_SEARCH_PASSWORD'), $display);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/', $display, 'The stable id itself is not printed.');
    }

    public function testSaysWhyAPersonWouldBeRefused(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:ldap:check'));

        self::assertSame(0, $tester->execute(['login' => 'enzo.petit']));
        self::assertStringContainsString('refusé : Peyra est réservé aux étudiants et aux enseignants du BTS CG.', $tester->getDisplay());
    }

    public function testAnUnknownLoginFails(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:ldap:check'));

        self::assertSame(1, $tester->execute(['login' => 'personne.inconnue']));
        self::assertStringContainsString('introuvable', $tester->getDisplay());
    }

    public function testWithoutALoginOnlyTheServiceAccountIsChecked(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:ldap:check'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('Personne', $tester->getDisplay());
    }
}
