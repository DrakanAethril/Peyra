<?php

declare(strict_types=1);

namespace App\Service\Shared\Storage;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The files of a dossier - its pièces, the documents generated for it - and the only service that
 * writes to the documents storage (config/packages/flysystem.yaml).
 *
 * Every file lives under its dossier's prefix, `dossiers/<id>/<category>/<random>.<extension>`:
 * the key never carries a name a person typed (that one is kept in the database, beside the key),
 * and every reading takes the dossier with the key and refuses a key outside that dossier's prefix
 * - the files' half of « every business row carries its dossier ». Copying a dossier (distribution,
 * restore points, conception § 3 bis) copies its prefix and hands back the new key of each file.
 *
 * Files are deleted with their dossier only (`deleteAll()`), by a person's gesture - a template or
 * a dossier deleted, a dossier taken back to a restore point: nothing is ever deleted
 * automatically (CLAUDE.md).
 */
final readonly class DossierFiles
{
    private const string CATEGORY = '/^[a-z][a-z0-9-]{0,39}$/';
    private const string EXTENSION = '/^[a-z0-9]{1,8}$/';

    public function __construct(
        #[Target('documents.storage')]
        private FilesystemOperator $storage,
    ) {
    }

    /** `dossiers/12/` */
    public static function prefix(int $dossierId): string
    {
        if ($dossierId < 1) {
            throw new \InvalidArgumentException('A dossier id is positive.');
        }

        return 'dossiers/'.$dossierId.'/';
    }

    /**
     * Stores an uploaded file, after the policy accepted it, and answers its key.
     *
     * @throws UploadRefusal
     */
    public function storeUpload(int $dossierId, string $category, UploadedFile $file, UploadPolicy $policy): string
    {
        $policy->check($file);
        $extension = strtolower(pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION));
        $key = $this->newKey($dossierId, $category, $extension);

        $stream = fopen($file->getPathname(), 'r');
        if (false === $stream) {
            throw new UploadRefusal('Le fichier n’a pas pu être reçu. Réessayez dans un instant.');
        }
        try {
            $this->storage->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }

        return $key;
    }

    /** Stores bytes the application produced (a generated PDF, an export) and answers their key. */
    public function storeContents(int $dossierId, string $category, string $extension, string $contents): string
    {
        $key = $this->newKey($dossierId, $category, $extension);
        $this->storage->write($key, $contents);

        return $key;
    }

    public function read(int $dossierId, string $key): string
    {
        return $this->storage->read($this->owned($dossierId, $key));
    }

    /** @return resource */
    public function readStream(int $dossierId, string $key)
    {
        return $this->storage->readStream($this->owned($dossierId, $key));
    }

    public function exists(int $dossierId, string $key): bool
    {
        return $this->storage->fileExists($this->owned($dossierId, $key));
    }

    public function size(int $dossierId, string $key): int
    {
        return $this->storage->fileSize($this->owned($dossierId, $key));
    }

    /**
     * Copies every file of a dossier under another one's prefix, keeping each file's place inside
     * it, and answers the new key of each old key - the table the dossier copier rewrites the
     * copied rows' keys with.
     *
     * @return array<string, string> old key => new key
     */
    public function copyAll(int $fromDossierId, int $toDossierId): array
    {
        $from = self::prefix($fromDossierId);
        $to = self::prefix($toDossierId);
        if ($from === $to) {
            throw new \InvalidArgumentException('A dossier is not copied onto itself.');
        }

        $map = [];
        $listing = $this->storage->listContents(rtrim($from, '/'), true)
            ->filter(static fn (StorageAttributes $item): bool => $item->isFile())
            ->sortByPath();
        foreach ($listing as $item) {
            $old = $item->path();
            $new = $to.substr($old, \strlen($from));
            $this->storage->copy($old, $new);
            $map[$old] = $new;
        }

        return $map;
    }

    /**
     * The keys of every file of a dossier, sorted: what a return to a restore point compares with
     * the keys its copy brings, to delete the files no row names any more.
     *
     * @return list<string>
     */
    public function keys(int $dossierId): array
    {
        $keys = [];
        foreach ($this->storage->listContents(rtrim(self::prefix($dossierId), '/'), true)->sortByPath() as $item) {
            if ($item->isFile()) {
                $keys[] = $item->path();
            }
        }

        return $keys;
    }

    /**
     * Deletes some files of a dossier, each key checked to be one of its own: the files a return to
     * a restore point replaced, or those of a copy that failed.
     *
     * @param list<string> $keys
     */
    public function deleteKeys(int $dossierId, array $keys): void
    {
        foreach ($keys as $key) {
            $this->storage->delete($this->owned($dossierId, $key));
        }
    }

    /**
     * Deletes every file of a dossier and answers how many there were: the dossier is deleted, or
     * its content is about to be replaced by a restore point's (App\Service\Dossier\Copy\DossierEraser).
     */
    public function deleteAll(int $dossierId): int
    {
        $prefix = rtrim(self::prefix($dossierId), '/');
        $count = 0;
        foreach ($this->storage->listContents($prefix, true) as $item) {
            if ($item->isFile()) {
                ++$count;
            }
        }
        if ($count > 0) {
            $this->storage->deleteDirectory($prefix);
        }

        return $count;
    }

    /** The key, once it is known to belong to the dossier. */
    public function owned(int $dossierId, string $key): string
    {
        $prefix = self::prefix($dossierId);
        if (!str_starts_with($key, $prefix) || str_contains($key, '..') || str_contains($key, '\\') || str_contains($key, '//')) {
            throw new \InvalidArgumentException(\sprintf('The key "%s" is not a file of dossier %d.', $key, $dossierId));
        }

        return $key;
    }

    private function newKey(int $dossierId, string $category, string $extension): string
    {
        if (1 !== preg_match(self::CATEGORY, $category)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a file category.', $category));
        }
        $extension = strtolower($extension);
        if (1 !== preg_match(self::EXTENSION, $extension)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a file extension.', $extension));
        }

        return self::prefix($dossierId).$category.'/'.bin2hex(random_bytes(16)).'.'.$extension;
    }
}
