<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Repository\Identity\UserRepository;
use App\Service\Identity\ClassBoard;
use App\Service\Identity\ClassRow;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * What the teacher shell draws on every screen: the class switcher of the top bar (design-ux.md
 * § 2.4) and the count of « À placer » in the classes' tabs.
 */
final readonly class ClassesExtension
{
    public function __construct(
        private ClassBoard $board,
        private UserRepository $users,
        private Security $security,
    ) {
    }

    /** @return array{year: SchoolYear|null, rows: list<ClassRow>} */
    #[AsTwigFunction('class_switcher')]
    public function switcher(): array
    {
        $year = $this->board->currentYear();
        $me = $this->security->getUser();

        return [
            'year' => $year,
            'rows' => null === $year ? [] : $this->board->rows($year, $me instanceof User ? $me : null),
        ];
    }

    #[AsTwigFunction('unplaced_count')]
    public function unplacedCount(): int
    {
        $year = $this->board->currentYear();

        return null === $year ? 0 : $this->users->countStudentsWithoutEnrolment($year);
    }
}
