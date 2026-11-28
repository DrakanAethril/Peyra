<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Accounting\JournalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The entry templates every new dossier receives (lot 3, step 3.3: « constitution et capital, des
 * modèles d'écriture »): the constitution of the company, the increases of capital and the
 * allocation of the result, called with F6 in the grid like the dossier's own. Read from
 * `fixtures/accounting/standard-templates.yaml`, where each names its journal by its type: the
 * dossier's first journal of that type (by code) takes it. Each is checked as the fiche checks it,
 * through TemplateSheet; a fault is a bug of the file and installs nothing more. The caller is in
 * the dossier's context; each template is flushed, so that the next one's code is checked against
 * the database.
 */
final readonly class StandardTemplates
{
    public function __construct(
        private TemplateSheet $sheet,
        private JournalRepository $journals,
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/fixtures/accounting/standard-templates.yaml')]
        private string $path,
    ) {
    }

    public function file(): TemplateFile
    {
        return TemplateFile::read($this->path);
    }

    /** @return int the number of templates installed */
    public function install(Dossier $dossier, User|string $by): int
    {
        $file = $this->file();
        foreach ($file->drafts as $index => $draft) {
            $type = $file->journalTypes[$index] ?? null;
            if (null !== $type) {
                $journal = $this->journals->findByType($dossier, $type)[0] ?? throw new \LogicException(\sprintf('The standard template %s asks a journal of type %s, which the dossier lacks.', $draft->code, $type->value));
                $draft->journal = $journal->getCode();
            }
            $fields = $this->sheet->check($draft, $dossier) ?? throw new \LogicException(\sprintf('standard-templates.yaml : %s : %s', $draft->code, implode(' ', $draft->errors)));
            $this->sheet->create($dossier, $fields, $by);
            $this->entityManager->flush();
        }

        return \count($file->drafts);
    }
}
