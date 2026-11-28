<?php

declare(strict_types=1);

namespace App\Entity\ThirdParty;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\ThirdParty\ThirdPartyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A third party of the dossier (« tiers »): a customer, a supplier, an employee or another one,
 * with its code (« C0003 »), the account it is kept on and - on a collective account - its
 * auxiliary code (« 401SCIE », the FEC's CompAuxNum), its details and its payment terms.
 *
 * A third party is not an account of the plan: on an entry line, « 401SCIE » names the collective
 * 401000 and this third party (design-ux.md § 5.2). It may have no account at all - a shop paid by
 * card whose ticket is entered straight in the bank. ThirdPartySheet and ThirdPartyImport are the
 * only writers; no balance is kept here.
 */
#[ORM\Entity(repositoryClass: ThirdPartyRepository::class)]
#[ORM\Table(name: 'third_party')]
#[ORM\UniqueConstraint(name: 'uniq_third_party_dossier_code', columns: ['dossier_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_third_party_dossier_auxiliary', columns: ['dossier_id', 'auxiliary_code'])]
class ThirdParty implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « C0003 »: capitals and digits, 1 to 12. */
    #[ORM\Column(length: 12)]
    private string $code;

    #[ORM\Column(length: 16, enumType: ThirdPartyKind::class)]
    private ThirdPartyKind $kind;

    /** Its name as invoices print it (« Scierie du Haut-Forez », « Girard, Jean et Anne »). */
    #[ORM\Column(length: 120)]
    private string $name;

    /** A private person rather than a business (no SIREN, no VAT number). */
    #[ORM\Column]
    private bool $individual = false;

    /** The account it is kept on: a collective (401000) with its auxiliary code, or a general account (431000). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Account $account = null;

    /** « 401SCIE »: set exactly when the account is collective. */
    #[ORM\Column(length: 17, nullable: true)]
    private ?string $auxiliaryCode = null;

    #[ORM\Column(length: 9, nullable: true)]
    private ?string $siren = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street2 = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    /** ISO 3166 alpha-2 (FR, DE, CA). */
    #[ORM\Column(length: 2)]
    private string $country = 'FR';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Embedded(class: PaymentTerms::class, columnPrefix: 'payment_terms_')]
    private PaymentTerms $paymentTerms;

    #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod = PaymentMethod::Transfer;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    /** A customer whose debt is doubtful (« créance douteuse », step 2.7): a marker, read by the inventory work of lot 3. */
    #[ORM\Column(options: ['default' => false])]
    private bool $doubtful = false;

    public function __construct(Dossier $dossier, string $code, ThirdPartyKind $kind, string $name)
    {
        $this->dossier = $dossier;
        $this->code = $code;
        $this->kind = $kind;
        $this->name = $name;
        $this->paymentTerms = PaymentTerms::default();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getKind(): ThirdPartyKind
    {
        return $this->kind;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isIndividual(): bool
    {
        return $this->individual;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getAuxiliaryCode(): ?string
    {
        return $this->auxiliaryCode;
    }

    /** What an entry line names it by: « 401SCIE », else the general account's number, else nothing. */
    public function getAccountReference(): ?string
    {
        return $this->auxiliaryCode ?? $this->account?->getNumber();
    }

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function getStreet2(): ?string
    {
        return $this->street2;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getPaymentTerms(): PaymentTerms
    {
        return $this->paymentTerms;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /** ThirdPartySheet and ThirdPartyImport are the callers: they check every field first. */
    public function describe(string $code, ThirdPartyKind $kind, string $name, bool $individual): void
    {
        $this->code = $code;
        $this->kind = $kind;
        $this->name = $name;
        $this->individual = $individual;
    }

    /** The account and the auxiliary code, together: a collective carries one, a general account none. */
    public function keepOn(?Account $account, ?string $auxiliaryCode): void
    {
        if ((null !== $auxiliaryCode) !== (null !== $account && $account->isCollective())) {
            throw new \LogicException('An auxiliary code goes with a collective account, and only with one.');
        }
        $this->account = $account;
        $this->auxiliaryCode = $auxiliaryCode;
    }

    public function identify(?string $siren, ?string $vatNumber): void
    {
        $this->siren = $siren;
        $this->vatNumber = $vatNumber;
    }

    public function locate(?string $street, ?string $street2, ?string $postalCode, ?string $city, string $country, ?string $contactName, ?string $email, ?string $phone): void
    {
        $this->street = $street;
        $this->street2 = $street2;
        $this->postalCode = $postalCode;
        $this->city = $city;
        $this->country = $country;
        $this->contactName = $contactName;
        $this->email = $email;
        $this->phone = $phone;
    }

    public function settle(PaymentTerms $terms, PaymentMethod $method, ?string $note): void
    {
        $this->paymentTerms = $terms;
        $this->paymentMethod = $method;
        $this->note = $note;
    }

    public function isDoubtful(): bool
    {
        return $this->doubtful;
    }

    /** DoubtfulReceivables alone sets it, for a customer. */
    public function markDoubtful(bool $doubtful): void
    {
        $this->doubtful = $doubtful;
    }
}
