<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Enum\Sales\SalesDocumentType;
use App\Service\Inventory\ArticleDraft;
use App\Service\Sales\SalesDocumentDraft;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/sales.yaml` read and checked for its shape (step 2.3): the articles (ArticleDraft,
 * checked by ArticleRules at the install), the numbering already used by the books, and the
 * commercial documents - a draft for SalesDocumentRules, its lines' designation and unit taken from
 * the article they name, or a document made from an earlier one (`made_from`, its rank; `date`
 * dates it otherwise than the dossier's date - a receipt on its day, step 2.4). Amounts are euros as
 * text, read exactly; a line's price may be left out on a price request, which asks for it.
 *
 * @phpstan-type Numbering array{type: SalesDocumentType, year: int, last: int, date: \DateTimeImmutable}
 * @phpstan-type DemoDocument array{type: SalesDocumentType, draft: ?SalesDocumentDraft, madeFrom: ?int, validate: bool, date?: \DateTimeImmutable}
 */
final readonly class DemoSalesFile
{
    /**
     * @param list<ArticleDraft> $articles
     * @param list<Numbering>    $numbering
     * @param list<DemoDocument> $documents
     */
    private function __construct(public array $articles, public array $numbering, public array $documents)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException($path.' : un dictionnaire est attendu.');
        }

        $articles = [];
        $known = [];
        foreach (self::list($data, 'articles') as $index => $entry) {
            $where = 'articles['.$index.']';
            $draft = new ArticleDraft();
            $draft->code = self::text($entry, 'code', $where);
            $draft->designation = self::text($entry, 'designation', $where);
            $draft->unit = self::text($entry, 'unit', $where);
            $price = self::optional($entry, 'price', $where);
            $draft->salePrice = null === $price ? '' : FrenchFormat::amount(self::euros($price, $where.'.price'));
            $purchase = self::optional($entry, 'purchase_price', $where);
            $draft->purchasePrice = null === $purchase ? '' : FrenchFormat::amount(self::euros($purchase, $where.'.purchase_price'));
            $draft->vatRate = self::optional($entry, 'rate', $where) ?? '';
            $draft->revenueAccount = self::optional($entry, 'account', $where) ?? '';
            $stock = $entry['stock'] ?? null;
            if (!\is_bool($stock)) {
                throw new \InvalidArgumentException($where.'.stock : true ou false est attendu.');
            }
            $draft->stockTracked = $stock;
            $articles[] = $draft;
            $known[$draft->code] = $draft;
        }

        $numbering = [];
        foreach (self::list($data, 'numbering') as $index => $entry) {
            $where = 'numbering['.$index.']';
            $type = SalesDocumentType::tryFrom(self::text($entry, 'type', $where)) ?? throw new \InvalidArgumentException($where.'.type : un type de document est attendu.');
            $year = $entry['year'] ?? null;
            $last = $entry['last'] ?? null;
            if (!\is_int($year) || !\is_int($last) || $last < 0) {
                throw new \InvalidArgumentException($where.' : une année et un dernier numéro entiers sont attendus.');
            }
            $numbering[] = ['type' => $type, 'year' => $year, 'last' => $last, 'date' => self::date($entry, $where)];
        }

        $documents = [];
        foreach (self::list($data, 'documents') as $index => $entry) {
            $where = 'documents['.$index.']';
            $type = SalesDocumentType::tryFrom(self::text($entry, 'type', $where)) ?? throw new \InvalidArgumentException($where.'.type : un type de document est attendu.');
            $validate = $entry['validate'] ?? false;
            if (!\is_bool($validate)) {
                throw new \InvalidArgumentException($where.'.validate : true ou false est attendu.');
            }
            $madeFrom = $entry['made_from'] ?? null;
            if (null !== $madeFrom) {
                if (!\is_int($madeFrom) || $madeFrom < 0 || $madeFrom >= $index) {
                    throw new \InvalidArgumentException($where.'.made_from : le rang d’un document plus haut est attendu.');
                }
                $document = ['type' => $type, 'draft' => null, 'madeFrom' => $madeFrom, 'validate' => $validate];
                if (isset($entry['date'])) {
                    $document['date'] = self::date($entry, $where);
                }
                $documents[] = $document;
                continue;
            }
            $draft = new SalesDocumentDraft();
            $draft->customer = self::text($entry, 'customer', $where);
            $draft->date = self::date($entry, $where)->format('Y-m-d');
            $draft->reference = self::optional($entry, 'reference', $where) ?? '';
            $rows = [];
            foreach (self::list($entry, 'lines', $where) as $position => $line) {
                $at = $where.'.lines['.$position.']';
                $code = self::text($line, 'article', $at);
                $article = $known[$code] ?? throw new \InvalidArgumentException($at.'.article : l’article '.$code.' n’est pas dans la liste.');
                $rows[] = [
                    'article' => $code,
                    'designation' => $article->designation,
                    'unit' => $article->unit,
                    'quantity' => str_replace('.', ',', self::text($line, 'quantity', $at)),
                    'unitPrice' => null === self::optional($line, 'price', $at) ? '' : FrenchFormat::amount(self::euros(self::text($line, 'price', $at), $at.'.price')),
                    'discount' => self::optional($line, 'discount', $at) ?? '',
                    'rate' => self::text($line, 'rate', $at),
                ];
            }
            $draft->readLines($rows);
            $documents[] = ['type' => $type, 'draft' => $draft, 'madeFrom' => null, 'validate' => $validate];
        }

        return new self($articles, $numbering, $documents);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    private static function list(array $data, string $key, string $where = ''): array
    {
        $list = $data[$key] ?? null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException(ltrim($where.'.'.$key, '.').' : une liste est attendue.');
        }
        $rows = [];
        foreach ($list as $index => $row) {
            if (!\is_array($row)) {
                throw new \InvalidArgumentException(ltrim($where.'.'.$key, '.').'['.$index.'] : un dictionnaire est attendu.');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function euros(string $text, string $where): Money
    {
        if (1 !== preg_match('/^\d+\.\d{2}$/', $text)) {
            throw new \InvalidArgumentException($where.' : un montant « 1234.56 » entre guillemets est attendu.');
        }

        return Money::euros($text);
    }

    /** @param array<mixed> $entry */
    private static function date(array $entry, string $where): \DateTimeImmutable
    {
        $text = self::text($entry, 'date', $where);

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $text) ?: throw new \InvalidArgumentException($where.'.date : une date « AAAA-MM-JJ » est attendue.');
    }

    /** @param array<mixed> $entry */
    private static function text(array $entry, string $key, string $where): string
    {
        return self::optional($entry, $key, $where) ?? throw new \InvalidArgumentException($where.'.'.$key.' : un texte est attendu.');
    }

    /** @param array<mixed> $entry */
    private static function optional(array $entry, string $key, string $where): ?string
    {
        $value = $entry[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un texte est attendu.');
        }

        return $value;
    }
}
