<?php

declare(strict_types=1);

namespace App\Platform\Payment\Providers;

/**
 * Which verification mechanism handled a delivery. The shared-token value is
 * retained as a schema value for a future trusted contract, but this lane
 * accepts only the Svix mechanism.
 */
enum SignatureMechanism: string
{
    case Svix = 'svix';
    case SharedToken = 'shared-token';
}
