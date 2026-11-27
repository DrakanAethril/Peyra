<?php

declare(strict_types=1);

namespace App\Tests\Service\Regulatory;

use App\Service\Regulatory\InvalidRegulatoryFile;
use App\Service\Regulatory\RegulatoryFile;
use PHPUnit\Framework\TestCase;

final class RegulatoryFileTest extends TestCase
{
    private const string VALID = <<<'YAML'
        millesime: 2026
        consulted_on: 2026-11-27
        entries:
          - key: social.smic_hourly
            label: SMIC horaire brut
            value: 12.02
            unit: EUR/heure
            effective_from: 2026-01-01
            source_url: https://www.urssaf.fr/accueil/outils-documentation/taux-baremes/montant-smic.html
            consulted_on: 2026-11-27
            verified: true
            note: Du 1er janvier au 31 mai 2026.
          - key: social.smic_hourly
            label: SMIC horaire brut
            value: 12.31
            unit: EUR/heure
            effective_from: 2026-06-01
            source_url: https://www.urssaf.fr/accueil/outils-documentation/taux-baremes/montant-smic.html
            consulted_on: 2026-11-27
            verified: true
            note: ""
          - key: fiscal.vat.simplified_regime_thresholds
            label: Régime simplifié - seuils
            value:
              sales_and_accommodation: {lower: 85000, normal: 945000}
              vat_due_condition: "TVA exigible de l'année < 15 000 €"
            unit: EUR
            effective_from: 2026-01-01
            source_url: null
            consulted_on: null
            verified: false
          - key: fiscal.tax_return_forms.millesime_2027
            label: Liasse de l'exercice 2026
            value: non publiée au 27/11/2026
            unit: text
            effective_from: 2027-01-01
            verified: false
        YAML;

    public function testReadsEveryEntryWithItsDates(): void
    {
        $entries = RegulatoryFile::parse(self::VALID, '2026.yaml', 2026);

        self::assertCount(4, $entries);
        self::assertSame('social.smic_hourly', $entries[0]->key);
        self::assertSame('12.02', $entries[0]->tree);
        self::assertSame('2026-01-01', $entries[0]->effectiveFrom->format('Y-m-d'));
        self::assertSame('2026-11-27', $entries[0]->consultedOn?->format('Y-m-d'));
        self::assertTrue($entries[0]->verified);
        self::assertSame('Du 1er janvier au 31 mai 2026.', $entries[0]->note);
        self::assertSame('12.31', $entries[1]->tree);
        self::assertNull($entries[1]->note, 'An empty note is no note.');
        self::assertSame(['sales_and_accommodation' => ['lower' => '85000', 'normal' => '945000'], 'vat_due_condition' => "TVA exigible de l'année < 15 000 €"], $entries[2]->tree);
        self::assertNull($entries[2]->sourceUrl);
        self::assertSame('2027-01-01', $entries[3]->effectiveFrom->format('Y-m-d'), 'A change already known for next year has its place.');
    }

    public function testAnEmptyMillesimeIsAFile(): void
    {
        self::assertSame([], RegulatoryFile::parse("millesime: 2025\nentries: []\n", '2025.yaml', 2025));
    }

    public function testEveryFaultIsNamedAndNothingIsRead(): void
    {
        $yaml = <<<'YAML'
            millesime: 2025
            author: moi
            entries:
              - key: Social.SMIC
                label: SMIC
                value: 12
                unit: EUR
                effective_from: 2026-01-01
                verified: false
              - key: payroll.smic
                label: SMIC
                value: 12
                unit: EUR
                effective_from: 2026-01-01
                verified: false
              - key: social.smic
                label: ""
                unit: EUR
                effective_from: 01/01/2026
                verified: oui
                colour: bleu
              - key: social.pss
                label: PSS
                value: 4005
                unit: EUR/mois
                effective_from: 2026-01-01
                verified: false
              - key: social.pss
                label: PSS
                value: 4005
                unit: EUR/mois
                effective_from: 2026-01-01
                verified: false
              - key: social.pass
                label: PASS
                value: 48060
                unit: EUR/an
                effective_from: 2026-01-01
                source_url: urssaf.fr
                verified: true
            YAML;

        try {
            RegulatoryFile::parse($yaml, '2026.yaml', 2026);
            self::fail('The file has faults.');
        } catch (InvalidRegulatoryFile $error) {
            self::assertSame('2026.yaml', $error->fileName);
            self::assertSame([
                'champ inconnu en tête de fichier : « author ».',
                '« millesime » doit valoir 2026, comme le nom du fichier.',
                'entrée 1 (Social.SMIC) : « key » doit être de la forme domaine.nom_en_snake_case.',
                'entrée 2 (payroll.smic) : domaine inconnu « payroll » (domaines : social, fiscal).',
                'entrée 3 (social.smic) : champ inconnu « colour ».',
                'entrée 3 (social.smic) : « label » est obligatoire.',
                'entrée 3 (social.smic) : « value » est obligatoire.',
                'entrée 3 (social.smic) : « effective_from » doit être une date AAAA-MM-JJ.',
                'entrée 3 (social.smic) : « verified » doit valoir true ou false.',
                'entrée 5 (social.pss) : même clé et même date d’effet que l’entrée 4.',
                'entrée 6 (social.pass) : « source_url » doit être une adresse http(s).',
                'entrée 6 (social.pass) : une valeur vérifiée dit où (« source_url ») et quand (« consulted_on ») elle a été lue.',
            ], $error->problems);
        }
    }

    public function testUnreadableYamlIsRefused(): void
    {
        $this->expectException(InvalidRegulatoryFile::class);
        $this->expectExceptionMessage('YAML illisible');
        RegulatoryFile::parse("millesime: 2026\nentries:\n  - {base: 4 PSS (192 240 €/an, 16 020 €/mois)}", '2026.yaml', 2026);
    }

    public function testTheRepositoryFilesAreValid(): void
    {
        $files = glob(\dirname(__DIR__, 3).'/config/regulatory/*.yaml') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $path) {
            self::assertMatchesRegularExpression('/^\d{4}\.yaml$/', basename($path));
            RegulatoryFile::parse((string) file_get_contents($path), basename($path), (int) basename($path, '.yaml'));
        }
        self::assertFileExists(\dirname(__DIR__, 3).'/config/regulatory/2025.yaml');
        self::assertFileExists(\dirname(__DIR__, 3).'/config/regulatory/2026.yaml');
    }
}
