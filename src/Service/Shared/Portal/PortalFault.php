<?php

declare(strict_types=1);

namespace App\Service\Shared\Portal;

/**
 * A motive of rejection on a simulated portal (design-ux.md A13; the social portal's DPAE at step
 * 4.2b, the tax portal's returns later): its code, the motive as the portal writes it, and the
 * explanation the guided level adds (Assistance::explainsPortalRejections()).
 */
final readonly class PortalFault
{
    public function __construct(public string $code, public string $motive, public string $explanation)
    {
    }
}
