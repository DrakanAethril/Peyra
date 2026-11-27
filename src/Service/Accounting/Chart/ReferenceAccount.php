<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

/** One account of a reference chart, as the ANC prints it: « 601 », « Achats stockés - Matières premières et fournitures ». */
final readonly class ReferenceAccount
{
    public function __construct(public string $number, public string $label)
    {
    }

    /** The number a dossier gives it: the reference number padded with zeros to six digits (« 601000 »). */
    public function dossierNumber(): string
    {
        return str_pad($this->number, AccountNumber::COPIED_LENGTH, '0');
    }
}
