<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\FormValue;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

final class FormValueTest extends TestCase
{
    public function testReadsSubmittedFields(): void
    {
        $form = $this->form();
        $form->submit(['label' => '  Achats  ', 'count' => '12', 'blank' => '', 'flag' => '1']);

        self::assertSame('  Achats  ', FormValue::string($form, 'label'));
        self::assertSame('Achats', FormValue::trimmed($form, 'label'));
        self::assertSame(12, FormValue::int($form, 'count'));
        self::assertSame(3, FormValue::int($form, 'label', 3));
        self::assertNull(FormValue::nullableInt($form, 'blank'));
        self::assertTrue(FormValue::bool($form, 'flag'));
        self::assertNull(FormValue::money($form, 'count'), 'A number is not an amount.');
    }

    public function testReadsAMoneyTheFieldHolds(): void
    {
        $form = $this->form(['amount' => Money::cents(262200)]);

        self::assertSame(262200, FormValue::money($form, 'amount')?->cents);
        self::assertFalse(FormValue::bool($form, 'flag'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return FormInterface<mixed>
     */
    private function form(array $data = []): FormInterface
    {
        return Forms::createFormFactory()->createBuilder(FormType::class, $data)
            ->add('label', TextType::class, ['trim' => false])
            ->add('count', TextType::class)
            ->add('blank', TextType::class)
            ->add('amount', FormType::class, ['compound' => false])
            ->add('flag', CheckboxType::class)
            ->getForm();
    }
}
