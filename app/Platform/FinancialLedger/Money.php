<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;
use OverflowException;

/**
 * Immutable money value represented as an integer number of minor units.
 *
 * Decimal catalog values are converted here at the read seam. No float input,
 * property, or arithmetic is permitted on this type.
 */
final readonly class Money
{
    public readonly int $minorUnits;

    /**
     * @param  mixed  $minorUnits  The runtime boundary is checked explicitly so
     *                             weak callers cannot truncate a float to int.
     */
    public function __construct(mixed $minorUnits)
    {
        if (! is_int($minorUnits)) {
            throw new \TypeError('Money minor units must be an integer.');
        }

        $this->minorUnits = $minorUnits;
    }

    /**
     * Convert a decimal string to the configured integer minor-unit value.
     *
     * @throws InvalidArgumentException when the value is not an exact decimal
     *                                  at the configured precision.
     */
    public static function fromDecimal(string $amount): int
    {
        $amount = trim($amount);
        $minorUnits = self::minorUnits();

        if (preg_match('/\A(-?)(\d+)(?:\.(\d+))?\z/D', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Money decimal must be a plain numeric string.');
        }

        $fraction = $matches[3] ?? '';
        $extraFraction = substr($fraction, $minorUnits);

        if ($extraFraction !== '' && rtrim($extraFraction, '0') !== '') {
            throw new InvalidArgumentException(
                "Money decimal has more than {$minorUnits} minor-unit digits."
            );
        }

        $factor = self::factor($minorUnits);
        $fractionMinor = (int) str_pad(substr($fraction, 0, $minorUnits), $minorUnits, '0');
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $maximumWhole = intdiv(PHP_INT_MAX - $fractionMinor, $factor);

        if (strlen($whole) > strlen((string) $maximumWhole)
            || (strlen($whole) === strlen((string) $maximumWhole) && $whole > (string) $maximumWhole)) {
            throw new OverflowException('Money decimal exceeds the supported integer range.');
        }

        $minorValue = ((int) $whole * $factor) + $fractionMinor;

        return $matches[1] === '-' ? -$minorValue : $minorValue;
    }

    public function toMinorInt(): int
    {
        return $this->minorUnits;
    }

    public function add(self $other): self
    {
        self::assertAdditionDoesNotOverflow($this->minorUnits, $other->minorUnits);

        return new self($this->minorUnits + $other->minorUnits);
    }

    public function subtract(self $other): self
    {
        return $this->add($other->negate());
    }

    public function negate(): self
    {
        if ($this->minorUnits === PHP_INT_MIN) {
            throw new OverflowException('Money value cannot be negated within the integer range.');
        }

        return new self(-$this->minorUnits);
    }

    public function compare(self $other): int
    {
        return $this->minorUnits <=> $other->minorUnits;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function format(): string
    {
        if ($this->minorUnits === PHP_INT_MIN) {
            throw new OverflowException('Money value cannot be formatted within the integer range.');
        }

        $factor = self::factor(self::minorUnits());
        $isNegative = $this->minorUnits < 0;
        $absolute = $isNegative ? -$this->minorUnits : $this->minorUnits;
        $whole = intdiv($absolute, $factor);
        $fraction = $absolute % $factor;
        $formattedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', (string) $whole);

        if ($formattedWhole === null) {
            throw new InvalidArgumentException('Money value could not be formatted.');
        }

        $formatted = $formattedWhole;

        if ($fraction !== 0) {
            $formatted .= ','.str_pad((string) $fraction, self::minorUnits(), '0', STR_PAD_LEFT);
        }

        return 'Rp '.($isNegative ? '-' : '').$formatted;
    }

    private static function minorUnits(): int
    {
        return (int) config('money.minor_units');
    }

    private static function factor(int $minorUnits): int
    {
        $factor = 1;

        for ($i = 0; $i < $minorUnits; $i++) {
            if ($factor > intdiv(PHP_INT_MAX, 10)) {
                throw new OverflowException('Money minor-unit factor exceeds the integer range.');
            }

            $factor *= 10;
        }

        return $factor;
    }

    private static function assertAdditionDoesNotOverflow(int $left, int $right): void
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new OverflowException('Money arithmetic exceeds the integer range.');
        }
    }
}
