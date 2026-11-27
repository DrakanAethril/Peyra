<?php

declare(strict_types=1);

namespace App\Service\Shared\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What Peyra accepts as an uploaded file: one platform rule, which each field may narrow and never
 * widen (the idea of Formatool's UploadPolicy, same author and licence, cut to what a teaching
 * dossier receives: pièces, statements, spreadsheets, e-invoices).
 *
 *     UploadPolicy::platform()                                       // the ceiling
 *     UploadPolicy::platform()->restrictTo('pdf', 'jpg', 'jpeg', 'png') // a pièce
 *     UploadPolicy::platform()->restrictTo('csv', 'ofx')->limitedTo(2 * 1024 * 1024)
 *
 * restrictTo() and limitedTo() throw when asked to step outside the platform rule, so a field that
 * tries is an error where it is declared. Three rules hold beside the list:
 * - the extension is checked against the type sniffed from the bytes, with the wrong-but-real
 *   answers a genuine file gives (`text/plain` for a CSV, `application/zip` for an XLSX);
 * - every segment of the name counts: « facture.pdf.exe » and « facture.exe.pdf » are refused;
 * - a name without extension is refused.
 *
 * The size ceiling sits under PHP's (64M, frankenphp/conf.d/10-app.ini), itself at the height of
 * nginx's client_max_body_size: a file above this ceiling reaches the application and is refused
 * with a sentence, rather than being dropped silently by a layer below.
 */
final readonly class UploadPolicy
{
    public const int PLATFORM_MAX_BYTES = 20 * 1024 * 1024;

    /** @var array<string, list<string>> extension => the types its bytes may sniff as */
    private const array PLATFORM = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        // A CSV saved by Excel sniffs as application/vnd.ms-excel, most others as text/plain.
        'csv' => ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
        // A bank statement in OFX is SGML or XML text.
        'ofx' => ['text/plain', 'application/xml', 'text/xml', 'application/x-ofx'],
        'xml' => ['application/xml', 'text/xml', 'text/plain'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
    ];

    /** Never accepted, whatever segment of the name carries them. */
    private const array FORBIDDEN = [
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'ps1', 'vbs', 'js', 'mjs', 'jar', 'sh', 'php',
        'phtml', 'phar', 'html', 'htm', 'svg', 'xhtml', 'dll', 'app', 'dmg', 'lnk',
    ];

    /** @param list<string> $extensions */
    private function __construct(
        public array $extensions,
        public int $maxBytes,
    ) {
    }

    public static function platform(): self
    {
        return new self(array_keys(self::PLATFORM), self::PLATFORM_MAX_BYTES);
    }

    /** The same rule, accepting only these extensions - each of which the platform accepts. */
    public function restrictTo(string ...$extensions): self
    {
        $kept = [];
        foreach ($extensions as $extension) {
            $extension = strtolower($extension);
            if (!\in_array($extension, $this->extensions, true)) {
                throw new \InvalidArgumentException(\sprintf('UploadPolicy cannot accept ".%s": it is not in the rule it narrows.', $extension));
            }
            $kept[] = $extension;
        }
        if ([] === $kept) {
            throw new \InvalidArgumentException('UploadPolicy needs at least one extension.');
        }

        return new self(array_values(array_unique($kept)), $this->maxBytes);
    }

    /** The same rule, with a lower size ceiling. */
    public function limitedTo(int $maxBytes): self
    {
        if ($maxBytes < 1 || $maxBytes > $this->maxBytes) {
            throw new \InvalidArgumentException(\sprintf('UploadPolicy cannot raise its ceiling to %d bytes.', $maxBytes));
        }

        return new self($this->extensions, $maxBytes);
    }

    /** The `accept` attribute of a file field: « .pdf,.jpg,.png ». */
    public function accept(): string
    {
        return implode(',', array_map(static fn (string $e): string => '.'.$e, $this->extensions));
    }

    /**
     * Null when the file is accepted, else the refusal. `$sniffedType` is the type read from the
     * bytes (Symfony's MIME guesser), never the one the browser declared.
     */
    public function refusal(string $originalName, string $sniffedType, int $bytes): ?string
    {
        $name = strtolower(trim(\Normalizer::normalize($originalName, \Normalizer::FORM_C) ?: $originalName));
        $segments = explode('.', $name);
        if (\count($segments) < 2 || '' === end($segments)) {
            return 'Ce fichier n’a pas d’extension (.pdf, .jpg…) : renommez-le puis envoyez-le de nouveau.';
        }
        array_shift($segments);
        foreach ($segments as $segment) {
            if (\in_array($segment, self::FORBIDDEN, true)) {
                return 'Ce type de fichier n’est jamais accepté.';
            }
        }
        $extension = (string) end($segments);
        if (!\in_array($extension, $this->extensions, true)) {
            return \sprintf('Les fichiers .%s ne sont pas acceptés ici. Formats acceptés : %s.', $extension, $this->readableList());
        }
        if (!\in_array(strtolower($sniffedType), self::PLATFORM[$extension], true)) {
            return \sprintf('Ce fichier ne contient pas ce que son extension .%s annonce.', $extension);
        }
        if ($bytes > $this->maxBytes) {
            return \sprintf('Ce fichier dépasse %s Mo, la taille acceptée.', self::megabytes($this->maxBytes));
        }
        if (0 === $bytes) {
            return 'Ce fichier est vide.';
        }

        return null;
    }

    /** The refusal of an uploaded file, PHP's own upload errors included. */
    public function refusalOf(UploadedFile $file): ?string
    {
        if (!$file->isValid()) {
            return match ($file->getError()) {
                \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => \sprintf('Ce fichier dépasse %s Mo, la taille acceptée.', self::megabytes($this->maxBytes)),
                \UPLOAD_ERR_PARTIAL, \UPLOAD_ERR_NO_FILE => 'Le fichier n’est pas arrivé en entier. Envoyez-le de nouveau.',
                default => 'Le fichier n’a pas pu être reçu. Réessayez dans un instant.',
            };
        }

        return $this->refusal($file->getClientOriginalName(), (string) $file->getMimeType(), (int) $file->getSize());
    }

    /** Throws the refusal, for a caller that has nothing else to do with it. */
    public function check(UploadedFile $file): void
    {
        $refusal = $this->refusalOf($file);
        if (null !== $refusal) {
            throw new UploadRefusal($refusal);
        }
    }

    private function readableList(): string
    {
        return implode(', ', array_map(static fn (string $e): string => '.'.$e, $this->extensions));
    }

    /** « 20 », « 1,5 »: megabytes to a tenth, in whole numbers. */
    private static function megabytes(int $bytes): string
    {
        $tenths = intdiv($bytes * 10, 1024 * 1024);

        return 0 === $tenths % 10 ? (string) intdiv($tenths, 10) : intdiv($tenths, 10).','.($tenths % 10);
    }
}
