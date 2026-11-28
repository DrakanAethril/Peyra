<?php

declare(strict_types=1);

namespace App\Service\Payroll\Plan;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollBody;
use App\Entity\Payroll\PayrollItem;
use App\Enum\Audit\AuditAction;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Installs the standard plan de paie (`fixtures/payroll/standard-plan.yaml`) into a dossier whose
 * plan is empty - « Installer le plan de paie standard », and the demonstration. Every item is
 * checked by PayrollItemRules like the fiche; the bodies its contributions go to are the dossier's
 * first of each kind, made with the file's default name when it has none. The regulatory values
 * the items name are not read here (the dossier's date may precede what is installed):
 * StandardPayrollPlanTest reads each one in 2025 and in 2026 from the millésime files. Runs inside
 * the dossier's context; the caller flushes.
 */
final readonly class PayrollPlanInstaller
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PayrollItemRepository $items,
        private PayrollBodyRepository $bodies,
        private AuditTrail $auditTrail,
        #[Autowire('%kernel.project_dir%/fixtures/payroll/standard-plan.yaml')]
        private string $path,
    ) {
    }

    /** @return int the number of items installed */
    public function installStandard(Dossier $dossier, User|string $by): int
    {
        if ($this->items->countInDossier($dossier) > 0) {
            throw new \LogicException('The plan de paie is not empty.');
        }
        $plan = PayrollPlanFile::readStandard($this->path);
        $count = $this->install($dossier, $plan['items'], $plan['bodies'], 'standard-plan.yaml');
        $this->auditTrail->inDossier($dossier, AuditAction::PayrollPlanInstalled, $by, \sprintf('Plan de paie standard installé (%d rubriques)', $count), ['dossier' => $dossier->getId(), 'items' => $count]);

        return $count;
    }

    /**
     * Items read by PayrollPlanFile, each checked by PayrollItemRules; a fault names the item and stops.
     *
     * @param list<array{draft: PayrollItemDraft, body: ?PayrollBodyKind}> $items
     * @param array<string, string>                                         $bodyNames the name of a body made for a kind the dossier lacks
     *
     * @return int the number of items installed
     */
    public function install(Dossier $dossier, array $items, array $bodyNames, string $where): int
    {
        /** @var array<string, PayrollBody> $bodies */
        $bodies = [];
        $codes = [];
        foreach ($this->items->findPlan($dossier) as $existing) {
            $codes[$existing->getCode()] = true;
        }
        foreach ($items as $item) {
            $body = null === $item['body'] ? null : ($bodies[$item['body']->value] ??= $this->body($dossier, $item['body'], $bodyNames, $where));
            $spec = PayrollItemRules::check($item['draft'], $body);
            if (null === $spec) {
                throw new \InvalidArgumentException(\sprintf('%s : rubrique %s : %s', $where, $item['draft']->code, implode(' ', $item['draft']->errors)));
            }
            if (isset($codes[$spec->code])) {
                throw new \InvalidArgumentException(\sprintf('%s : la rubrique %s existe déjà.', $where, $spec->code));
            }
            $codes[$spec->code] = true;
            $this->entityManager->persist(new PayrollItem($dossier, $spec));
        }

        return \count($items);
    }

    /** @param array<string, string> $bodyNames */
    private function body(Dossier $dossier, PayrollBodyKind $kind, array $bodyNames, string $where): PayrollBody
    {
        $body = $this->bodies->findFirstOfKind($dossier, $kind);
        if (null !== $body) {
            return $body;
        }
        $name = $bodyNames[$kind->value] ?? throw new \InvalidArgumentException(\sprintf('%s : aucun organisme « %s » au dossier.', $where, $kind->label()));
        $body = new PayrollBody($dossier, $kind, $name, '');
        $this->entityManager->persist($body);

        return $body;
    }
}
