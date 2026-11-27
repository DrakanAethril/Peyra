<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Dossier\Dossier;
use App\Enum\Dossier\LegalForm;
use App\Enum\Dossier\ProfitTaxRegime;
use App\Enum\Dossier\VatRegime;
use App\Security\DossierRouteListener;
use App\Service\Dossier\DossierShell;
use App\Service\Dossier\DossierShellView;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * The PGI shell's reading of the dossier in hand (App\Service\Dossier\DossierShell), for
 * templates/dossier/_layout.html.twig, and the choices of the fiche société.
 */
final readonly class DossierExtension
{
    public function __construct(private DossierShell $shell, private RequestStack $requests)
    {
    }

    #[AsTwigFunction('dossier_shell')]
    public function shell(Dossier $dossier): DossierShellView
    {
        return $this->shell->view($dossier, true === $this->requests->getMainRequest()?->attributes->get(DossierRouteListener::DATE_MOVED));
    }

    /** @return array{legalForms: list<array{value: string, label: string}>, vatRegimes: list<array{value: string, label: string}>, profitTaxRegimes: list<array{value: string, label: string}>} */
    #[AsTwigFunction('company_options')]
    public function companyOptions(): array
    {
        return [
            'legalForms' => array_map(static fn (LegalForm $form): array => ['value' => $form->value, 'label' => $form->label()], LegalForm::cases()),
            'vatRegimes' => array_map(static fn (VatRegime $regime): array => ['value' => $regime->value, 'label' => $regime->label()], VatRegime::cases()),
            'profitTaxRegimes' => array_map(static fn (ProfitTaxRegime $regime): array => ['value' => $regime->value, 'label' => $regime->label()], ProfitTaxRegime::cases()),
        ];
    }
}
