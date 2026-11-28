<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Inventory\Article;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Inventory\ArticleRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the articles (step 2.3): checked by ArticleRules, written, recorded in the
 * dossier's trail. The caller flushes. A document's lines name an article by its code and keep
 * what they printed: deleting an article changes no document.
 */
final readonly class ArticleSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleRepository $articles,
        private AccountRepository $accounts,
        private AuditTrail $auditTrail,
    ) {
    }

    /** What may be saved, or null with the draft's errors filled. */
    public function check(ArticleDraft $draft, Dossier $dossier, ?Article $article = null): ?ArticleFields
    {
        return ArticleRules::check(
            $draft,
            function (string $code) use ($dossier, $article): bool {
                $holder = $this->articles->findOneByCode($dossier, $code);

                return null !== $holder && $holder !== $article;
            },
            fn (string $number): ?int => $this->accounts->findOneByNumber($dossier, $number)?->getClassDigit(),
        );
    }

    public function create(Dossier $dossier, ArticleFields $fields, User|string $by): Article
    {
        $article = new Article($dossier, $fields->code);
        self::write($article, $fields);
        $this->entityManager->persist($article);
        $this->auditTrail->inDossier($dossier, AuditAction::ArticleCreated, $by, \sprintf('Article %s %s créé', $fields->code, $fields->designation), ['code' => $fields->code]);

        return $article;
    }

    public function update(Article $article, ArticleFields $fields, User $by): void
    {
        $before = $article->getCode();
        self::write($article, $fields);
        $this->auditTrail->inDossier($article->getDossier(), AuditAction::ArticleChanged, $by, \sprintf('Article %s %s modifié', $fields->code, $fields->designation), ['code' => $fields->code, 'before' => $before]);
    }

    public function delete(Article $article, User $by): void
    {
        $this->auditTrail->inDossier($article->getDossier(), AuditAction::ArticleDeleted, $by, \sprintf('Article %s %s supprimé', $article->getCode(), $article->getDesignation()), ['code' => $article->getCode()]);
        $this->entityManager->remove($article);
    }

    private static function write(Article $article, ArticleFields $fields): void
    {
        $article->describe($fields->code, $fields->designation, $fields->unit, $fields->salePrice, $fields->vatRate, $fields->revenueAccount, $fields->stockTracked);
    }
}
