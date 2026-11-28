<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierProfile;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the teacher chose in the distribution's steps (design-ux.md § 4.2), carried from one GET
 * step to the next and posted by the last: the students ticked (`pick[]`; null on the first
 * display, when everybody without a copy of the template yet is ticked), how (`mode`), each
 * student's group and profile (`group[id]`, `profile[id]`) and the date the dossiers start at
 * (`start`, ISO; empty = the template's date).
 */
final readonly class DistributionChoices
{
    /**
     * @param list<int>|null              $picked
     * @param array<int, int>             $groups   by student id
     * @param array<int, DossierProfile|null> $profiles by student id
     */
    public function __construct(
        public ?array $picked,
        public DistributionMode $mode,
        public array $groups,
        public array $profiles,
        public string $start,
    ) {
    }

    public static function first(): self
    {
        return new self(null, DistributionMode::Individual, [], [], '');
    }

    public static function fromQuery(Request $request): self
    {
        return self::read(
            '' === QueryValue::string($request, 'step') ? null : QueryValue::intList($request, 'pick'),
            QueryValue::trimmed($request, 'mode'),
            PostValue::queryStringsById($request, 'group'),
            PostValue::queryStringsById($request, 'profile'),
            QueryValue::trimmed($request, 'start'),
        );
    }

    public static function fromPost(Request $request): self
    {
        return self::read(
            PostValue::intList($request, 'pick'),
            PostValue::trimmed($request, 'mode'),
            PostValue::stringsById($request, 'group'),
            PostValue::stringsById($request, 'profile'),
            PostValue::trimmed($request, 'start'),
        );
    }

    /**
     * The parameters that carry these choices to another step (`step` marks they were made).
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        $query = ['step' => 1, 'pick' => $this->picked ?? [], 'mode' => $this->mode->value];
        if ([] !== $this->groups) {
            $query['group'] = $this->groups;
        }
        $profiles = array_filter(array_map(static fn (?DossierProfile $profile): ?string => $profile?->value, $this->profiles));
        if ([] !== $profiles) {
            $query['profile'] = $profiles;
        }
        if ('' !== $this->start) {
            $query['start'] = $this->start;
        }

        return $query;
    }

    /**
     * @param list<int>|null        $picked
     * @param array<int, string>    $groups
     * @param array<int, string>    $profiles
     */
    private static function read(?array $picked, string $mode, array $groups, array $profiles, string $start): self
    {
        $numbers = [];
        foreach ($groups as $id => $number) {
            if (1 === preg_match('/^[1-9]\d{0,2}$/', $number)) {
                $numbers[$id] = (int) $number;
            }
        }
        $chosen = [];
        foreach ($profiles as $id => $profile) {
            $chosen[$id] = DossierProfile::tryFrom($profile);
        }

        return new self($picked, DistributionMode::tryFrom($mode) ?? DistributionMode::Individual, $numbers, $chosen, $start);
    }
}
