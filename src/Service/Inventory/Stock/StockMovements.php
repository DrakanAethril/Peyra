<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Inventory\Article;
use App\Entity\Inventory\StockCount;
use App\Entity\Inventory\StockMovement;
use App\Entity\Sales\SalesDocument;
use App\Enum\Audit\AuditAction;
use App\Enum\Inventory\StockMovementKind;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Inventory\ArticleRepository;
use App\Repository\Inventory\StockMovementRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\FrenchFormat;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the stock movements (step 2.5): a movement typed by hand, checked by
 * StockMovementRules then against what the stock holds (StockBalances: never below zero, on its
 * day nor on a later one), recorded under a lock on its article's row and in the dossier's trail;
 * and the movements a validated document makes - an exit per line of a delivery note, an entry
 * per line of a receipt (valued at the line's net, the order's price and discount), for the
 * articles whose stock is followed, in the main storage place; and the adjustment of each gap a
 * validated physical inventory found (step 2.5b).
 *
 * The stock is managed in the « inventaire intermittent » way of the French chart: a movement
 * writes no entry; the variation of stocks is recorded at the closing (lot 3).
 */
final readonly class StockMovements
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleRepository $articles,
        private StockMovementRepository $movements,
        private AuditTrail $auditTrail,
    ) {
    }

    /** What may be recorded, or null with the draft's errors filled. */
    public function check(StockMovementDraft $draft, Dossier $dossier): ?StockMovementFields
    {
        $fields = StockMovementRules::check($draft, fn (string $code): ?bool => $this->articles->findOneByCode($dossier, $code)?->isStockTracked(), $dossier->getDossierDate());
        if (null === $fields) {
            return null;
        }
        $article = $this->article($dossier, $fields->article);
        $short = $this->shortfall($article, [self::move($fields)]);
        if (null !== $short) {
            $draft->errors['quantity'] = $short;

            return null;
        }

        return $fields;
    }

    /** Recorded and flushed, in one transaction under the article's lock; refused when the stock no longer holds it. */
    public function record(Dossier $dossier, StockMovementFields $fields, User|string $by): StockMovement
    {
        return $this->entityManager->wrapInTransaction(function () use ($dossier, $fields, $by): StockMovement {
            $article = $this->article($dossier, $fields->article);
            $this->entityManager->lock($article, LockMode::PESSIMISTIC_WRITE);
            $short = $this->shortfall($article, [self::move($fields)]);
            if (null !== $short) {
                throw new StockShortage($short);
            }
            $movement = new StockMovement($dossier, $article, $fields->date, $fields->kind, $fields->quantity, $fields->value, $fields->location, $fields->toLocation, $fields->label);
            $this->entityManager->persist($movement);
            $this->auditTrail->inDossier($dossier, AuditAction::StockMovementRecorded, $by, \sprintf('%s de %s %s : %s %s', $fields->kind->label(), $fields->article, $article->getDesignation(), FrenchFormat::number($fields->quantity), $article->getUnit()), ['article' => $fields->article, 'kind' => $fields->kind->value, 'quantity' => (string) $fields->quantity]);
            $this->entityManager->flush();

            return $movement;
        });
    }

    /** @return list<string> why a delivery note may not be validated: the stock it would take out is not there */
    public function documentRefusals(SalesDocument $document): array
    {
        if (SalesDocumentType::Delivery !== $document->getType()) {
            return [];
        }
        $refusals = [];
        foreach ($this->documentMoves($document) as [$article, $moves]) {
            $short = $this->shortfall($article, $moves);
            if (null !== $short) {
                $refusals[] = $short;
            }
        }

        return $refusals;
    }

    /**
     * The movements of a delivery note or a receipt being validated - its number given, inside its
     * transaction, which flushes them: its articles locked in id order, the stock asked again.
     */
    public function recordDocument(SalesDocument $document, User|string $by): void
    {
        $moves = $this->documentMoves($document);
        if ([] === $moves) {
            return;
        }
        usort($moves, static fn (array $a, array $b): int => $a[0]->getId() <=> $b[0]->getId());
        $dossier = $document->getDossier();
        $label = \sprintf('%s %s', $document->getType()->label(), $document->getNumber() ?? '');
        foreach ($moves as [$article, $articleMoves]) {
            $this->entityManager->lock($article, LockMode::PESSIMISTIC_WRITE);
            if (SalesDocumentType::Delivery === $document->getType()) {
                $short = $this->shortfall($article, $articleMoves);
                if (null !== $short) {
                    throw new StockShortage($short);
                }
            }
            foreach ($articleMoves as $move) {
                $this->entityManager->persist(new StockMovement($dossier, $article, $move->date, $move->kind, $move->quantity, $move->value, $move->location, null, $label, $document));
            }
        }
        $this->auditTrail->inDossier($dossier, AuditAction::StockMovementRecorded, $by, \sprintf('Mouvements de stock de %s', $label), ['document' => $document->getId()]);
    }

    /**
     * Why the adjustment of a gap a physical inventory found may not be recorded - it would leave the
     * stock short on a later day (an exit recorded after the count) -, or null.
     */
    public function countShortfall(StockCount $count, Article $article, BigDecimal $gap): ?string
    {
        return $this->shortfall($article, [new StockMove(\PHP_INT_MAX, $count->getDate(), StockMovementKind::Adjustment, $gap, null, $count->getLocation())]);
    }

    /**
     * The adjustment of a gap a physical inventory found, at its day and in its storage place - inside
     * the inventory's validation, which locked the article, asked countShortfall() and flushes.
     */
    public function adjustForCount(StockCount $count, Article $article, BigDecimal $gap): void
    {
        $this->entityManager->persist(new StockMovement($count->getDossier(), $article, $count->getDate(), StockMovementKind::Adjustment, $gap, null, $count->getLocation(), null, $count->getTitle(), null, $count));
    }

    /**
     * The movements a document's lines make, by article: a delivery note's are exits, a receipt's
     * entries at the line's net; every other type makes none.
     *
     * @return list<array{0: Article, 1: list<StockMove>}>
     */
    private function documentMoves(SalesDocument $document): array
    {
        $kind = match ($document->getType()) {
            SalesDocumentType::Delivery => StockMovementKind::Exit,
            SalesDocumentType::Receipt => StockMovementKind::Entry,
            default => null,
        };
        if (null === $kind) {
            return [];
        }
        $byArticle = [];
        $articles = [];
        $key = \PHP_INT_MAX - 1000;
        foreach ($document->getLines() as $line) {
            if (null === $line->article) {
                continue;
            }
            $article = $this->articles->findOneByCode($document->getDossier(), $line->article);
            if (null === $article || !$article->isStockTracked()) {
                continue;
            }
            $articles[$article->getCode()] = $article;
            $byArticle[$article->getCode()][] = new StockMove(++$key, $document->getDate(), $kind, $line->quantity, StockMovementKind::Entry === $kind ? $line->net() : null, StockMovement::MAIN_LOCATION);
        }
        $result = [];
        foreach ($byArticle as $code => $moves) {
            $result[] = [$articles[$code], $moves];
        }

        return $result;
    }

    /**
     * The sentence of what the stock would lack, or null when it holds what goes out.
     *
     * @param list<StockMove> $added
     */
    private function shortfall(Article $article, array $added): ?string
    {
        $recorded = array_map(static fn (StockMovement $movement): StockMove => $movement->toMove(), $this->movements->findOfArticle($article));
        $short = StockBalances::shortfall($recorded, $added);
        if (null === $short) {
            return null;
        }

        return \sprintf('Stock insuffisant de %s %s : il manquerait %s %s au %s le %s.', $article->getCode(), $article->getDesignation(), FrenchFormat::number($short['missing']), $article->getUnit(), $short['location'], $short['date']->format('d/m/Y'));
    }

    private function article(Dossier $dossier, string $code): Article
    {
        return $this->articles->findOneByCode($dossier, $code) ?? throw new \LogicException('The rules checked the article.');
    }

    /** A movement still to record: its key after every recorded one. */
    private static function move(StockMovementFields $fields): StockMove
    {
        return new StockMove(\PHP_INT_MAX, $fields->date, $fields->kind, $fields->quantity, $fields->value, $fields->location, $fields->toLocation);
    }
}
