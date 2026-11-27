<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\Shared\Money;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class FormatExtensionTest extends KernelTestCase
{
    public function testFiltersTakeMoneyOrCentsAndPrintNothingForNull(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $template = $twig->createTemplate(
            '{{ m|amount }}|{{ c|amount_cell }}|{{ z|amount_cell }}|{{ c|amount_eur }}|{{ b|balance }}|{{ n|amount }}|{{ r|percent }}|{{ d|date_fr }}|{{ d|day_month(2026) }}|{{ d|date_chip }}|{{ d|period }}|{{ 102|duration }}|{{ 3956|whole_euros }}|{{ "151.67"|hours }}|{{ "2.4"|quantity }}'
        );

        $html = $template->render([
            'm' => Money::cents(324840),
            'c' => 262200,
            'z' => 0,
            'b' => -13800,
            'n' => null,
            'r' => '20.00',
            'd' => new \DateTimeImmutable('2026-11-12'),
        ]);

        $t = "\u{202F}";
        $s = "\u{00A0}";
        self::assertSame(
            "3{$t}248,40|2{$t}622,00||2{$t}622,00{$s}€|138,00{$s}C||20{$s}%|12/11/2026|12/11|12 nov. 2026|novembre 2026|1{$s}h{$s}42|3{$t}956|151,67{$s}h|2,40",
            $html,
        );
    }
}
