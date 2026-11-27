<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Service\ThirdParty\ThirdPartyDraft;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/third_parties.yaml` read whole, each entry as the draft a person would have typed:
 * DemoInstaller then checks every one with ThirdPartyRules against the dossier's plan, as the form
 * does. A fault of shape names its entry and installs nothing.
 */
final readonly class DemoThirdPartyFile
{
    private const array TEXTS = [
        'code' => 'code', 'kind' => 'kind', 'name' => 'name', 'account' => 'account', 'auxiliary' => 'auxiliaryCode',
        'siren' => 'siren', 'vat' => 'vatNumber', 'street' => 'street', 'street2' => 'street2', 'postal_code' => 'postalCode',
        'city' => 'city', 'country' => 'country', 'contact' => 'contactName', 'email' => 'email', 'phone' => 'phone',
        'method' => 'paymentMethod', 'note' => 'note',
    ];

    /** @param list<ThirdPartyDraft> $drafts */
    private function __construct(public array $drafts)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['third_parties'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « third_parties » est attendue.');
        }
        $drafts = [];
        foreach ($list as $index => $entry) {
            $where = 'third_parties['.$index.']';
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($where.' : un dictionnaire est attendu.');
            }
            $draft = new ThirdPartyDraft();
            foreach ($entry as $key => $value) {
                if (isset(self::TEXTS[$key])) {
                    if (!\is_string($value)) {
                        throw new \InvalidArgumentException(\sprintf('%s.%s : un texte entre guillemets est attendu.', $where, $key));
                    }
                    $draft->{self::TEXTS[$key]} = $value;
                } elseif ('individual' === $key) {
                    $draft->individual = true === $value;
                } elseif ('terms' === $key && \is_array($value) && \is_string($value['kind'] ?? null)) {
                    $draft->termKind = $value['kind'];
                    $draft->termDays = \is_int($value['days'] ?? null) ? (string) $value['days'] : '';
                    $draft->termDay = \is_int($value['day'] ?? null) ? (string) $value['day'] : '';
                } else {
                    throw new \InvalidArgumentException(\sprintf('%s.%s : clé inconnue ou mal formée.', $where, \is_string($key) ? $key : (string) json_encode($key)));
                }
            }
            $drafts[] = $draft;
        }

        return new self($drafts);
    }
}
