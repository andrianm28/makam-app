<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by `GateActivationRecorder` when a caller attempts to record a
 * gate activation/deactivation without an evidence reference or a reason.
 * requirements.md Negative criteria: "No gate activation without recorded
 * evidence, owner, and date." — the "date" half is `occurred_at`, always
 * supplied by the recorder itself (never caller-supplied), so it cannot be
 * omitted; this exception covers the two halves a caller could otherwise
 * leave out.
 */
final class MissingActivationEvidenceException extends InvalidArgumentException {}
