<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryVisitationPolicies\Schemas;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Support\Design\IndonesianDate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The shared policy form — one policy per cemetery.
 *
 * `cemetery_id` is a Select whose options are cemeteries WITHOUT a policy
 * (create) plus the row's own cemetery (edit, where the Select is
 * disabled — the single record can never be moved onto a second
 * cemetery). `operating_hours` is stored as the model's
 * `{weekday: {open, close}|null}` JSON but edited as seven per-weekday
 * rows (buka toggle + open/close times); the two page hooks
 * (`expandOperatingHours` on fill, `collapseOperatingHours` on create/
 * save) map between the shapes, so the model's own `saving` guard remains
 * the single authority on the stored shape.
 *
 * A weekday the toggle leaves off becomes `null` (closed that weekday);
 * the time fields only matter for enabled days. The model guard rejects
 * malformed times with `InvalidArgumentException` inside the same write
 * transaction — the admin sees the honest refusal, never a silently
 * mangled policy.
 */
final class CemeteryVisitationPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('cemetery_id')
                    ->label('Makam')
                    ->required()
                    ->searchable()
                    ->helperText('Satu kebijakan per makam; hanya makam tanpa kebijakan yang dapat dipilih.')
                    ->options(function (Select $component): array {
                        $query = Cemetery::query()
                            ->orderBy('name')
                            ->whereNotIn('id', CemeteryVisitationPolicy::query()->select('cemetery_id'));

                        $record = $component->getRecord();

                        if ($record instanceof CemeteryVisitationPolicy) {
                            // The edit page keeps its own cemetery in the
                            // options so the disabled Select can label it.
                            $query->orWhere('id', $record->cemetery_id);
                        }

                        return $query->pluck('name', 'id')->all();
                    })
                    ->disabled(fn (Select $component): bool => $component->getContainer()->getOperation() === 'edit')
                    ->columnSpanFull(),

                TextInput::make('daily_capacity')
                    ->label('Kapasitas harian (jumlah pengunjung)')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->helperText('Jumlah maksimal pengunjung yang dapat terdaftar untuk satu tanggal.')
                    ->columnSpanFull(),

                Section::make('Jam kunjungan per hari')
                    ->description('Matikan hari untuk menutup makam pada hari tersebut.')
                    ->schema(self::weekdayRows())
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return list<Grid>
     */
    private static function weekdayRows(): array
    {
        $rows = [];

        foreach (CemeteryVisitationPolicy::WEEKDAY_KEYS as $key) {
            $rows[] = Grid::make(3)
                ->schema([
                    Toggle::make("hours_{$key}_enabled")
                        ->label(IndonesianDate::weekdayLabel($key))
                        ->default(true),

                    TextInput::make("hours_{$key}_open")
                        ->label('Jam buka')
                        ->placeholder('08:00')
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                        ->helperText('HH:MM'),

                    TextInput::make("hours_{$key}_close")
                        ->label('Jam tutup')
                        ->placeholder('17:00')
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                        ->helperText('HH:MM'),
                ]);
        }

        return $rows;
    }

    /**
     * Stored shape → form shape: split `operating_hours` into the
     * per-weekday fields the form edits. Days without an entry become
     * disabled toggles with the placeholder times.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function expandOperatingHours(array $data): array
    {
        $hours = is_array($data['operating_hours'] ?? null) ? $data['operating_hours'] : [];

        foreach (CemeteryVisitationPolicy::WEEKDAY_KEYS as $key) {
            $entry = $hours[$key] ?? null;

            $data["hours_{$key}_enabled"] = $entry !== null;
            $data["hours_{$key}_open"] = is_array($entry) ? $entry['open'] : '08:00';
            $data["hours_{$key}_close"] = is_array($entry) ? $entry['close'] : '17:00';
        }

        unset($data['operating_hours']);

        return $data;
    }

    /**
     * Form shape → stored shape: collapse the per-weekday fields into the
     * model's `{weekday: {open, close}|null}` JSON (days with the toggle
     * off are `null` — closed), and drop the temporary fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function collapseOperatingHours(array $data): array
    {
        $hours = [];

        foreach (CemeteryVisitationPolicy::WEEKDAY_KEYS as $key) {
            if ((bool) ($data["hours_{$key}_enabled"] ?? false)) {
                $hours[$key] = [
                    'open' => (string) ($data["hours_{$key}_open"] ?? '08:00'),
                    'close' => (string) ($data["hours_{$key}_close"] ?? '17:00'),
                ];
            } else {
                $hours[$key] = null;
            }

            unset($data["hours_{$key}_enabled"], $data["hours_{$key}_open"], $data["hours_{$key}_close"]);
        }

        $data['operating_hours'] = $hours;

        return $data;
    }
}
