<?php

declare(strict_types=1);

namespace App\Command\Identity;

use App\Service\Identity\DirectoryProbe;
use App\Service\Identity\DirectoryUnavailable;
use App\Service\Identity\EntryPolicy;
use App\Service\Identity\LdapDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnostic, never scheduled (docs/production.md): does the directory answer, does the service
 * account bind, and - given a login - how does Peyra read that person (the login attribute, the
 * stable id, the name, the groups) and would the entry rule let them in. It asks the real directory
 * in every environment, the test one included, and prints nothing secret: no password, and no
 * value of the stable id beyond the attribute it was read from.
 */
#[AsCommand(name: 'app:ldap:check', description: 'Dit si l’annuaire répond, si le compte de service se lie, et comment une personne y est lue')]
final class LdapCheckCommand extends Command
{
    public function __construct(
        private readonly DirectoryProbe $probe,
        private readonly LdapDirectory $directory,
        private readonly EntryPolicy $policy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::OPTIONAL, 'Un identifiant de connexion à chercher dans l’annuaire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configuration = $this->probe->configuration();

        $io->title('Annuaire');
        $io->definitionList(
            ['Adresse' => $configuration->address],
            ['Chiffrement' => '' === $configuration->encryption ? 'aucun' : $configuration->encryption],
            ['Certificat d’autorité' => match ($configuration->caCertReadable) {
                null => 'aucun (LDAP_TLS_CA_CERT_PATH vide)',
                true => $configuration->caCertPath.' (lisible)',
                false => $configuration->caCertPath.' (ABSENT ou illisible)',
            }],
            ['Base de recherche' => $configuration->baseDn],
            ['Compte de service' => $configuration->searchDn],
            ['Mot de passe du compte de service' => $configuration->hasSearchPassword ? 'renseigné' : 'VIDE'],
        );

        $result = $this->probe->probe();
        if (!$result->outcome->isSuccess()) {
            $io->error(array_filter([$result->outcome->label(), $result->detail]));

            return Command::FAILURE;
        }
        $io->success($result->outcome->label());

        $login = $input->getArgument('login');
        if (!\is_string($login) || '' === trim($login)) {
            return Command::SUCCESS;
        }

        try {
            $person = $this->directory->find(trim($login));
        } catch (DirectoryUnavailable $exception) {
            $io->error(['La recherche de la personne a échoué.', $exception->getPrevious()?->getMessage() ?? $exception->getMessage()]);

            return Command::FAILURE;
        }
        if (null === $person) {
            $io->error(\sprintf('Personne d’identifiant « %s » : introuvable, ou sans identifiant stable (voir LDAP_USERNAME_ATTRIBUTE et PEYRA_LDAP_STABLE_ID).', trim($login)));

            return Command::FAILURE;
        }

        $decision = $this->policy->decide($person->login, $person->stableId, $person->groups);
        $io->section('Personne');
        $io->definitionList(
            ['DN' => $person->dn],
            ['Identifiant' => $person->login],
            ['Identifiant stable' => 'lu dans '.strstr($person->stableId, ':', true)],
            ['Nom' => $person->displayName],
            ['Nom de tri' => $person->sortName],
            ['Adresse' => $person->email ?? '(aucune)'],
            ['Groupes' => [] === $person->groups ? '(aucun)' : implode(', ', $person->groups)],
            ['Règle d’entrée' => $decision->isAdmitted()
                ? \sprintf('entre comme %s (%s)', mb_strtolower($decision->kind->label()), $decision->basis->label())
                : 'refusé : '.$decision->refusal->message()],
        );

        return Command::SUCCESS;
    }
}
