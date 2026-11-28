<?php

declare(strict_types=1);

namespace App\Controller\Banking;

use App\Entity\Banking\BankStatement;
use App\Entity\Dossier\Dossier;
use App\Repository\Banking\BankStatementRepository;
use App\Security\Voter\DossierRightVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the Banque screens share: a statement of the dossier whose journal the person reads (a
 * profile that closes the bank journals answers 404), and a POST's token.
 */
trait BankingControllerTrait
{
    private function readableStatement(Dossier $dossier, int $id, BankStatementRepository $statements): BankStatement
    {
        $statement = $statements->find($id);
        if (null === $statement || $statement->getDossier() !== $dossier || !$this->isGranted(DossierRightVoter::JOURNAL_READ, $statement->getJournal())) {
            throw new NotFoundHttpException('No such statement.');
        }

        return $statement;
    }

    private function refuseBadToken(Dossier $dossier, Request $request): void
    {
        if (!$this->isCsrfTokenValid('bank-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }
    }
}
