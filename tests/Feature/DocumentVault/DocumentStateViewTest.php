<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\DocumentVault\DocumentState;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class DocumentStateViewTest extends TestCase
{
    public function test_the_state_mapping_renders_each_required_state_contract(): void
    {
        $idle = Blade::render('<x-document-vault.state state="idle" />');
        $uploading = Blade::render('<x-document-vault.state state="uploading" progress="42" cancellable="1" />');
        $scanning = Blade::render('<x-document-vault.state state="scanning" />');
        $accepted = Blade::render('<x-document-vault.state state="accepted" />');
        $rejected = Blade::render('<x-document-vault.state state="rejected" reason="File type is not allowed." />');

        $this->assertStringContainsString('Ready to upload', $idle);
        $this->assertStringContainsString('42%', $uploading);
        $this->assertStringContainsString('Cancel upload', $uploading);
        $this->assertStringContainsString('Scanning', $scanning);
        $this->assertStringContainsString('300 seconds', $scanning);
        $this->assertStringContainsString('Accepted', $accepted);
        $this->assertStringContainsString('File type is not allowed.', $rejected);
        $this->assertStringContainsString('Try again', $rejected);
    }

    public function test_unaccepted_states_only_render_safe_file_metadata_and_never_preview_markup(): void
    {
        foreach ([DocumentState::Uploading, DocumentState::Quarantined, DocumentState::Scanning, DocumentState::Rejected] as $state) {
            $html = Blade::render(
                '<x-document-vault.state :state="$state->value" filename="secret.pdf" type="PDF" size="12 KB" />',
                compact('state'),
            );

            $this->assertStringContainsString('secret.pdf', $html);
            $this->assertStringContainsString('PDF', $html);
            $this->assertStringContainsString('12 KB', $html);
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringNotContainsString('preview', strtolower($html));
            $this->assertStringNotContainsString('thumbnail', strtolower($html));
        }
    }
}
