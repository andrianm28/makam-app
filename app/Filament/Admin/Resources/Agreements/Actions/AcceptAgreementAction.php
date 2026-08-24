<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Agreements\Actions;

use App\Domain\AgreementCertificate\Actions\AcceptAgreement;
use App\Domain\AgreementCertificate\AgreementStatus;
use App\Domain\AgreementCertificate\Models\Agreement;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The 'Terima' header action on `ViewAgreement` — the AC2 acceptance
 * binding: draft → accepted, stamping the acceptor, the accepted quote
 * (optional), and the EXACT agreement version row being accepted
 * (`Actions\AcceptAgreement`), audited `AGREEMENT_ACCEPTED` + outbox
 * `agreement.accepted.v1`.
 *
 * Visible only on a draft row. `AcceptAgreement` records the actor role
 * `ActorRole::CUSTOMER` on its audit row because the accepting party is
 * always the customer — an admin invoking this action does so on the
 * customer's behalf (that signature choice is documented on the Action).
 */
final class AcceptAgreementAction
{
    public static function make(Agreement $agreement): Action
    {
        return Action::make('terima')
            ->label('Terima Perjanjian')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (): bool => $agreement->status === AgreementStatus::Draft->value)
            ->requiresConfirmation()
            ->modalHeading('Terima perjanjian versi ini?')
            ->modalDescription(
                'Penerimaan mengikat perjanjian ini ke versi yang tepat '
                .'dan nama pelanggan sebagai pihak yang menerima (AC2), lalu tercatat di audit.'
            )
            ->schema([
                TextInput::make('quote_id')
                    ->label('Referensi penawaran (opsional)')
                    ->maxLength(255)
                    ->helperText('Nomor penawaran yang disetujui, jika ada; tercatat di audit.'),
            ])
            ->action(
                fn (array $data) => self::run(
                    $agreement,
                    filled($data['quote_id'] ?? null) ? (string) $data['quote_id'] : null,
                ),
            );
    }

    private static function run(Agreement $agreement, ?string $quoteId): void
    {
        $actor = app(ActorContext::class);

        try {
            app(AcceptAgreement::class)(
                $agreement,
                (string) $actor->identityReference,
                $quoteId,
                (string) $agreement->getKey(),
            );

            Notification::make()->success()->title('Perjanjian diterima.')->send();
            redirect()->route('filament.admin.resources.persetujuan.view', ['record' => $agreement->getKey()]);
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Penerimaan perjanjian gagal')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
