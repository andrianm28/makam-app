<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ExampleData;

use App\Support\ExampleData\DemoContactData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DemoContactDataTest extends TestCase
{
    public function test_email_is_deterministic_and_uses_a_reserved_domain(): void
    {
        $first = DemoContactData::email(0);
        $second = DemoContactData::email(0);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/@example\.(com|org|net)$/', $first);
    }

    public function test_email_varies_by_index(): void
    {
        $this->assertNotSame(DemoContactData::email(0), DemoContactData::email(1));
    }

    #[DataProvider('phoneIndexes')]
    public function test_phone_is_deterministic_and_matches_the_reserved_block(int $index): void
    {
        $first = DemoContactData::phone($index);
        $second = DemoContactData::phone($index);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^08118990\d{4}$/', $first);
    }

    /**
     * @return list<array{0: int}>
     */
    public static function phoneIndexes(): array
    {
        return [[0], [1], [9999]];
    }

    public function test_phone_matches_the_booking_wizard_customer_mobile_validation_pattern(): void
    {
        // The exact pattern `SaveBookingDraftStep::validateCustomer()` enforces
        // for customer_mobile — confirmed against the real regex during this
        // plan's own research: ^(\+62|62|0)[0-9]{9,13}$
        $this->assertMatchesRegularExpression('/^(\+62|62|0)[0-9]{9,13}$/', DemoContactData::phone(0));
    }

    public function test_person_name_is_deterministic_and_carries_the_contoh_marker(): void
    {
        $first = DemoContactData::personName(0);

        $this->assertSame($first, DemoContactData::personName(0));
        $this->assertStringContainsString('Contoh', $first);
    }

    public function test_person_name_varies_by_index(): void
    {
        $this->assertNotSame(DemoContactData::personName(0), DemoContactData::personName(1));
    }
}
