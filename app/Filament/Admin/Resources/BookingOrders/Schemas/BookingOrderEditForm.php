<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

/**
 * The edit page's schema, kept deliberately minimal per the plan's Task 4
 * brief: a single disabled `internal_note` field plus an explanatory hint.
 *
 * There is no persisted `internal_note` column this phase and no writable
 * `orders` column at all (`Order::update()` throws — the model is
 * append-only), so nothing on this page can be saved: `EditBookingOrder`
 * hides the save action. The field is rendered disabled so the page reads
 * honestly as a read-only internal-note placeholder for the future
 * document-attachment phase rather than as a form that can persist.
 */
final class BookingOrderEditForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Textarea::make('internal_note')
                    ->label('Catatan Internal')
                    ->disabled()
                    ->helperText(
                        'Pesanan bersifat append-only: seluruh perubahan status dicatat melalui '
                        .'riwayat transisi pada halaman detail, bukan melalui edit langsung. Halaman '
                        .'ini disediakan untuk penempelan dokumen pada fase berikutnya.'
                    )
                    ->placeholder('Tidak dapat diubah pada fase ini.'),
            ]);
    }
}
