<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Service\Shared\DateValue;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/hiring.yaml` read whole (step 4.2b): the DPAE the demonstration deposited, each an
 * employee's matricule and the dossier's day of deposit. DemoInstaller deposits them through
 * HiringFormalities, the portal's own door. A fault of shape names its line.
 */
final readonly class DemoHiringFile
{
    /** @param list<array{number: string, on: \DateTimeImmutable}> $declarations */
    private function __construct(public array $declarations)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['declarations'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « declarations » est attendue.');
        }
        $declarations = [];
        foreach ($list as $index => $entry) {
            $number = \is_array($entry) ? ($entry['number'] ?? null) : null;
            $on = \is_array($entry) && \is_string($entry['on'] ?? null) ? DateValue::parse($entry['on']) : null;
            if (!\is_string($number) || null === $on) {
                throw new \InvalidArgumentException(\sprintf('%s : declarations[%d] : un matricule « number » et une date « on » (« 2026-03-30 ») sont attendus.', $path, $index));
            }
            $declarations[] = ['number' => $number, 'on' => $on];
        }

        return new self($declarations);
    }
}
