<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Identity\User;
use App\Enum\Identity\LoginOutcome;
use App\Service\Identity\AccountProvisioner;
use App\Service\Identity\Directory;
use App\Service\Identity\DirectoryPerson;
use App\Service\Identity\DirectoryUnavailable;
use App\Service\Identity\EntryPolicy;
use App\Service\Identity\LoginJournal;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * One sign-in, from the login typed to the account: throttling (per address and per login), the
 * directory (search, then a bind as the person), the entry rule, the account. Every outcome is
 * written to the sign-in journal. The refusal reaching the person is a sentence of
 * design-ux.md § 4.1, never a technical reason; a wrong password and an unknown login read the same.
 *
 * Kept out of src/Service/ because it reads the real clock: a sign-in is dated by the server.
 */
final readonly class SignIn
{
    public const string BAD_CREDENTIALS = 'Identifiant ou mot de passe incorrect.';
    public const string DIRECTORY_DOWN = 'L’annuaire de l’établissement ne répond pas. Réessayez dans un instant.';

    public function __construct(
        private Directory $directory,
        private EntryPolicy $policy,
        private AccountProvisioner $provisioner,
        private LoginJournal $journal,
        private ClockInterface $clock,
        private RateLimiterFactoryInterface $loginIpLimiter,
        private RateLimiterFactoryInterface $loginNameLimiter,
    ) {
    }

    /**
     * @throws CustomUserMessageAuthenticationException with the sentence to show
     */
    public function withPassword(string $login, #[\SensitiveParameter] string $password, ?string $ip): User
    {
        $now = $this->clock->now();
        $byName = $this->loginNameLimiter->create(mb_strtolower($login));
        $byIp = $this->loginIpLimiter->create($ip ?? 'unknown');
        $nameLimit = $byName->consume();
        $ipLimit = $byIp->consume();
        if (!$nameLimit->isAccepted() || !$ipLimit->isAccepted()) {
            $retry = max($nameLimit->getRetryAfter()->getTimestamp(), $ipLimit->getRetryAfter()->getTimestamp());
            $minutes = max(1, (int) ceil(($retry - $now->getTimestamp()) / 60));
            $this->journal->record($now, $login, LoginOutcome::Throttled, ip: $ip);
            throw new CustomUserMessageAuthenticationException(\sprintf('Trop de tentatives. Réessayez dans %d minute%s.', $minutes, $minutes > 1 ? 's' : ''));
        }

        try {
            $person = $this->directory->find($login);
            $opens = null !== $person && $this->directory->checkPassword($person, $password);
        } catch (DirectoryUnavailable) {
            $this->journal->record($now, $login, LoginOutcome::DirectoryUnavailable, ip: $ip);
            throw new CustomUserMessageAuthenticationException(self::DIRECTORY_DOWN);
        }
        if (null === $person || !$opens) {
            $this->journal->record($now, $login, LoginOutcome::BadCredentials, ip: $ip);
            throw new CustomUserMessageAuthenticationException(self::BAD_CREDENTIALS);
        }

        $user = $this->admit($login, $person, $now, $ip);
        $byName->reset();

        return $user;
    }

    /**
     * The development sign-in (DevLoginController): no password, the entry rule all the same.
     *
     * @throws CustomUserMessageAuthenticationException
     */
    public function withoutPassword(string $login, ?string $ip): User
    {
        $now = $this->clock->now();
        $person = $this->directory->find($login);
        if (null === $person) {
            throw new CustomUserMessageAuthenticationException(self::BAD_CREDENTIALS);
        }

        return $this->admit($login, $person, $now, $ip);
    }

    private function admit(string $login, DirectoryPerson $person, \DateTimeImmutable $now, ?string $ip): User
    {
        $decision = $this->policy->decide($person->login, $person->stableId, $person->groups);
        if (!$decision->isAdmitted()) {
            $this->journal->record($now, $login, LoginOutcome::ofRefusal($decision->refusal), displayName: $person->displayName, ip: $ip);
            throw new CustomUserMessageAuthenticationException($decision->refusal->message());
        }
        $user = $this->provisioner->provision($person, $decision, $now);
        $this->journal->record($now, $person->login, LoginOutcome::Success, $user, ip: $ip);

        return $user;
    }
}
