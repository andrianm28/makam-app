<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use RuntimeException;

/**
 * What `Console\Commands\SpineWatchdogCommand` reports on every detected
 * degradation — a purpose-built class rather than a generic
 * `RuntimeException` so an error tracker (Sentry or equivalent) groups and
 * filters on it by type, and so a future alert-routing rule can match on
 * `instanceof SpineDegradedException` specifically. The message carries
 * only counts and durations — never a payload, event name, or identifier;
 * see the watchdog command's own doc block for why.
 */
final class SpineDegradedException extends RuntimeException {}
