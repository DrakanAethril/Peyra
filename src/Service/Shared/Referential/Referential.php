<?php

declare(strict_types=1);

namespace App\Service\Shared\Referential;

use App\Enum\Shared\BtsProcess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The référentiel of the BTS CG, versioned data (`config/referential/bts-cg.yaml`): its four
 * professional exams, its seven processes, their 37 activities and their components, with the
 * official wordings. Missions aim at activities by their code (A1.2), positioning is decided per
 * activity, the passeport and the exam mode read the exams.
 *
 * Read once and checked whole - every process of BtsProcess in order, codes that follow their
 * parent (A1.2 under P1, A1.2.1 under A1.2) without gap, an exam named for every activity and
 * component -; a fault names its place and nothing is served. The file never changes while the
 * application runs, so it is kept for the life of the worker.
 */
final class Referential
{
    private ?string $edition = null;

    /** @var list<ReferentialExam>|null */
    private ?array $exams = null;

    /** @var list<ReferentialProcess>|null */
    private ?array $processes = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/referential/bts-cg.yaml')]
        private readonly string $file,
    ) {
    }

    /** The text's edition (« 2014 »: the arrêté of 3 November 2014 as amended). */
    public function edition(): string
    {
        $this->load();

        return (string) $this->edition;
    }

    /** @return list<ReferentialExam> E41, E42, E5, E6 */
    public function exams(): array
    {
        $this->load();

        return $this->exams ?? [];
    }

    public function exam(string $code): ?ReferentialExam
    {
        foreach ($this->exams() as $exam) {
            if ($exam->code === $code) {
                return $exam;
            }
        }

        return null;
    }

    /** @return list<ReferentialProcess> P1 to P7 */
    public function processes(): array
    {
        $this->load();

        return $this->processes ?? [];
    }

    public function process(BtsProcess $process): ReferentialProcess
    {
        foreach ($this->processes() as $candidate) {
            if ($candidate->process === $process) {
                return $candidate;
            }
        }

        throw new InvalidReferentialFile($this->file.' : le processus '.$process->value.' manque.');
    }

    /** @return list<ReferentialActivity> the 37 activities, in the text's order */
    public function activities(): array
    {
        $activities = [];
        foreach ($this->processes() as $process) {
            foreach ($process->activities as $activity) {
                $activities[] = $activity;
            }
        }

        return $activities;
    }

    /** An activity by its code (« A1.2 »), or null. */
    public function activity(string $code): ?ReferentialActivity
    {
        foreach ($this->activities() as $activity) {
            if ($activity->code === $code) {
                return $activity;
            }
        }

        return null;
    }

    private function load(): void
    {
        if (null !== $this->processes) {
            return;
        }
        $data = Yaml::parseFile($this->file);
        if (!\is_array($data)) {
            throw new InvalidReferentialFile($this->file.' : un dictionnaire est attendu.');
        }
        $edition = $data['edition'] ?? null;
        if (!\is_string($edition) || '' === $edition) {
            throw new InvalidReferentialFile($this->file.' : « edition » est attendu.');
        }
        $exams = $this->readExams($data['exams'] ?? null);
        $codes = array_map(static fn (ReferentialExam $exam): string => $exam->code, $exams);
        $rawProcesses = $data['processes'] ?? null;
        if (!\is_array($rawProcesses) || !array_is_list($rawProcesses) || \count($rawProcesses) !== \count(BtsProcess::cases())) {
            throw new InvalidReferentialFile($this->file.' : « processes » liste les sept processus.');
        }
        $processes = [];
        foreach (BtsProcess::cases() as $index => $expected) {
            $processes[] = $this->readProcess($rawProcesses[$index], $expected, $codes);
        }
        $this->edition = $edition;
        $this->exams = $exams;
        $this->processes = $processes;
    }

    /** @return list<ReferentialExam> */
    private function readExams(mixed $raw): array
    {
        if (!\is_array($raw) || [] === $raw || !array_is_list($raw)) {
            throw new InvalidReferentialFile($this->file.' : « exams » liste les épreuves.');
        }
        $exams = [];
        foreach ($raw as $index => $entry) {
            $where = \sprintf('%s : exams[%d]', $this->file, $index);
            if (!\is_array($entry)) {
                throw new InvalidReferentialFile($where.' : un dictionnaire est attendu.');
            }
            $coefficient = $entry['coefficient'] ?? null;
            if (!\is_int($coefficient) || $coefficient < 1) {
                throw new InvalidReferentialFile($where.' : « coefficient » est un entier positif.');
            }
            $code = self::text($entry, 'code', $where);
            if (1 !== preg_match('/^E\d{1,2}$/', $code) || isset($exams[$code])) {
                throw new InvalidReferentialFile($where.' : code d’épreuve invalide ou répété ('.$code.').');
            }
            $exams[$code] = new ReferentialExam(
                $code,
                self::text($entry, 'unit', $where),
                self::text($entry, 'title', $where),
                $coefficient,
                self::text($entry, 'form', $where),
                self::flag($entry, 'on_workstation', $where),
                self::flag($entry, 'verified', $where),
            );
        }

        return array_values($exams);
    }

    /** @param list<string> $examCodes */
    private function readProcess(mixed $raw, BtsProcess $expected, array $examCodes): ReferentialProcess
    {
        $where = $this->file.' : '.$expected->value;
        if (!\is_array($raw) || ($raw['code'] ?? null) !== $expected->value) {
            throw new InvalidReferentialFile($where.' : attendu à cette place, dans l’ordre P1 à P7.');
        }
        $rawActivities = $raw['activities'] ?? null;
        if (!\is_array($rawActivities) || [] === $rawActivities || !array_is_list($rawActivities)) {
            throw new InvalidReferentialFile($where.' : « activities » est attendu.');
        }
        $number = substr($expected->value, 1);
        $activities = [];
        foreach ($rawActivities as $index => $entry) {
            $code = \sprintf('A%s.%d', $number, $index + 1);
            $at = $where.' : '.$code;
            if (!\is_array($entry) || ($entry['code'] ?? null) !== $code) {
                throw new InvalidReferentialFile($at.' : attendu à cette place (codes suivis, sans trou).');
            }
            $exams = self::examsOf($entry, $at, $examCodes) ?? throw new InvalidReferentialFile($at.' : « exams » est attendu.');
            $rawComponents = $entry['components'] ?? null;
            if (!\is_array($rawComponents) || [] === $rawComponents || !array_is_list($rawComponents)) {
                throw new InvalidReferentialFile($at.' : « components » est attendu.');
            }
            $components = [];
            foreach ($rawComponents as $rank => $component) {
                $componentCode = \sprintf('%s.%d', $code, $rank + 1);
                $there = $where.' : '.$componentCode;
                if (!\is_array($component) || ($component['code'] ?? null) !== $componentCode) {
                    throw new InvalidReferentialFile($there.' : attendu à cette place (codes suivis, sans trou).');
                }
                $components[] = new ReferentialComponent(
                    $componentCode,
                    self::text($component, 'title', $there),
                    self::examsOf($component, $there, $examCodes) ?? $exams,
                    self::note($component, $there),
                );
            }
            $activities[] = new ReferentialActivity($code, $expected, self::text($entry, 'title', $at), $exams, $components, self::flag($entry, 'verified', $at), self::note($entry, $at));
        }

        return new ReferentialProcess($expected, self::text($raw, 'title', $where), $activities, self::flag($raw, 'verified', $where), self::note($raw, $where));
    }

    /**
     * @param array<mixed> $entry
     * @param list<string> $examCodes
     *
     * @return list<string>|null
     */
    private static function examsOf(array $entry, string $where, array $examCodes): ?array
    {
        if (!\array_key_exists('exams', $entry)) {
            return null;
        }
        $raw = $entry['exams'];
        if (!\is_array($raw) || [] === $raw || !array_is_list($raw)) {
            throw new InvalidReferentialFile($where.' : « exams » liste au moins une épreuve.');
        }
        $exams = [];
        foreach ($raw as $code) {
            if (!\is_string($code) || !\in_array($code, $examCodes, true)) {
                throw new InvalidReferentialFile($where.' : épreuve inconnue ('.(\is_scalar($code) ? (string) $code : '?').').');
            }
            $exams[] = $code;
        }

        return $exams;
    }

    /** @param array<mixed> $entry */
    private static function text(array $entry, string $key, string $where): string
    {
        $value = $entry[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new InvalidReferentialFile(\sprintf('%s : « %s » est attendu.', $where, $key));
        }

        return $value;
    }

    /** @param array<mixed> $entry */
    private static function flag(array $entry, string $key, string $where): bool
    {
        $value = $entry[$key] ?? null;
        if (!\is_bool($value)) {
            throw new InvalidReferentialFile(\sprintf('%s : « %s » vaut true ou false.', $where, $key));
        }

        return $value;
    }

    /** @param array<mixed> $entry */
    private static function note(array $entry, string $where): ?string
    {
        if (!\array_key_exists('note', $entry)) {
            return null;
        }

        return self::text($entry, 'note', $where);
    }
}
