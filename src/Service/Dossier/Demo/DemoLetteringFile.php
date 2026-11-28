<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Enum\Accounting\LetteringMode;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/letterings.yaml` read and checked for its shape: the demonstration's automatic
 * letterings, each an account, the third party on a collective and a mode. What they letter is
 * Lettering's to decide (a lettering that finds nothing fails the installation).
 */
final readonly class DemoLetteringFile
{
    /** @param list<array{account: string, thirdParty: ?string, mode: LetteringMode}> $letterings */
    private function __construct(public array $letterings)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['letterings'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « letterings » est attendue.');
        }

        $letterings = [];
        foreach ($list as $index => $entry) {
            $where = 'letterings['.$index.']';
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($where.' : un dictionnaire est attendu.');
            }
            $account = $entry['account'] ?? null;
            if (!\is_string($account) || 1 !== preg_match('/^\d{6,8}$/', $account)) {
                throw new \InvalidArgumentException($where.'.account : un numéro de compte entre guillemets est attendu.');
            }
            $thirdParty = $entry['third_party'] ?? null;
            if (null !== $thirdParty && (!\is_string($thirdParty) || '' === trim($thirdParty))) {
                throw new \InvalidArgumentException($where.'.third_party : le code d’un tiers est attendu.');
            }
            $by = $entry['by'] ?? null;
            $mode = \is_string($by) ? LetteringMode::tryFrom($by) : null;
            if (null === $mode) {
                throw new \InvalidArgumentException($where.'.by : « amount » ou « document » est attendu.');
            }
            $letterings[] = ['account' => $account, 'thirdParty' => null === $thirdParty ? null : trim($thirdParty), 'mode' => $mode];
        }

        return new self($letterings);
    }
}
