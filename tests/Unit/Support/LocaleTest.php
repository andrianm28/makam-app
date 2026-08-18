<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Public-beta readiness: with no `lang/` directory and `APP_LOCALE`
 * defaulting to `en`, any validation rule this codebase does not
 * hand-translate itself fell through to Laravel's English default — see
 * `lang/id/validation.php`'s own doc block. These tests prove the default
 * is now `id` and that the translation files actually resolve, not just
 * that the config value changed.
 */
final class LocaleTest extends TestCase
{
    public function test_the_application_locale_defaults_to_indonesian(): void
    {
        $this->assertSame('id', App::getLocale());
    }

    public function test_the_fallback_locale_stays_english(): void
    {
        $this->assertSame('en', App::getFallbackLocale());
    }

    public function test_a_generic_required_validation_error_renders_in_indonesian(): void
    {
        $validator = Validator::make([], ['email' => 'required']);

        $this->assertStringContainsString('wajib diisi', $validator->errors()->first('email'));
    }

    /**
     * The English set (Laravel's own bundled default, never copied into
     * this app's lang/ directory) must still resolve — it is the
     * `fallback_locale`, and lang/id/passwords.php's known gap (see class
     * doc block) depends on it staying reachable.
     */
    public function test_english_validation_strings_remain_reachable_as_the_fallback_set(): void
    {
        $this->assertSame(
            'The email field is required.',
            $this->translateInLocale('en'),
        );
    }

    public function test_a_generic_email_validation_error_renders_in_indonesian(): void
    {
        $validator = Validator::make(['email' => 'not-an-email'], ['email' => 'email']);

        $this->assertStringContainsString('alamat email yang valid', $validator->errors()->first('email'));
    }

    public function test_pagination_strings_are_indonesian(): void
    {
        $this->assertSame('&laquo; Sebelumnya', Lang::get('pagination.previous'));
        $this->assertSame('Selanjutnya &raquo;', Lang::get('pagination.next'));
    }

    public function test_auth_strings_are_indonesian(): void
    {
        $this->assertStringContainsString('tidak cocok', Lang::get('auth.failed'));
    }

    /**
     * Confirms the English set is still complete and reachable as the
     * fallback — sanity-checks the fallback_locale assertion above against
     * real content, not just the config value.
     */
    private function translateInLocale(string $locale): string
    {
        return Lang::get('validation.required', ['attribute' => 'email'], $locale);
    }
}
