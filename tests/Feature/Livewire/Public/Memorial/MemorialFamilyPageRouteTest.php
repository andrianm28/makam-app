<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Memorial;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Actions\GrantMemorialEditor;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/kenangan/{profileId}` — `MemorialFamilyPage`'s dedicated route-level
 * test, matching this repository's own existing route-test convention
 * (`AkunIndexRouteTest`'s pattern: real `$this->get(...)` HTTP round-trips).
 * `MemorialPublicPageTest` already covers this component's own behavior
 * (content submission, media upload, QR rotation, privacy change) via
 * `Livewire::test()`; this file adds the missing real-route seam the FFI
 * restyle ticket (`.scratch/ffi-clone-whole-frontend/issues/
 * 08-memorial-pages-restyle.md`) requires, rather than shipping a visual
 * change with no route-level seam covering it.
 */
final class MemorialFamilyPageRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function profile(): MemorialProfile
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba Rute',
            'slug' => 'tpu-uji-coba-rute-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 2',
        ]);
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->getKey()]);
        $profile = app(CreateMemorialProfile::class)($grave, 'user:1', 'operator', MemorialPrivacyMode::DEFAULT);
        $profile->forceFill(['display_name' => 'Almarhumah Siti Uji'])->save();

        return $profile;
    }

    public function test_a_guest_gets_the_uniform_not_visible_state_via_the_real_route(): void
    {
        $profile = $this->profile();

        $response = $this->get("/kenangan/{$profile->getKey()}");

        $response->assertOk();
        $response->assertSee('Memorial tidak tersedia');
        $response->assertDontSee('Almarhumah Siti Uji');
    }

    public function test_an_editor_sees_the_ffi_visual_markers_and_existing_manage_capabilities(): void
    {
        $profile = $this->profile();
        $editor = User::factory()->create();
        app(GrantMemorialEditor::class)($profile, $editor->id, (string) Str::uuid(), 'admin:1', 'admin');

        $response = $this->actingAs($editor)->get("/kenangan/{$profile->getKey()}");

        $response->assertOk();
        $html = $response->getContent();
        $this->assertNotFalse($html);

        // FFI container gutters (matches marketplace/akun's already-restyled
        // pages, not the older bare `px-4`).
        $this->assertStringContainsString('mx-auto max-w-content px-4 md:px-6 lg:px-8', $html);

        // A real page-identity <h1>, reusing this page's own existing
        // "halaman kenangan" copy rather than inventing new text.
        $this->assertStringContainsString('<h1 class="text-3xl font-semibold tracking-tight text-neutral-900 mb-6">Halaman Kenangan</h1>', $html);

        // The former mis-promoted <h1> is now the section-level <h2> its
        // siblings already use.
        $this->assertStringContainsString('<h2 class="text-lg font-semibold text-neutral-900">Nama yang ditampilkan</h2>', $html);

        // Existing manage/edit capabilities are unchanged and still present:
        // display-name field, content form, media upload, QR section,
        // privacy selector.
        $response->assertSee('Simpan nama');
        $response->assertSee('Tulis kenangan');
        $response->assertSee('Kirim catatan');
        $response->assertSee('Unggah foto');
        $response->assertSee('Kode QR kunjungan');
        $response->assertSee('Privasi');
    }
}
