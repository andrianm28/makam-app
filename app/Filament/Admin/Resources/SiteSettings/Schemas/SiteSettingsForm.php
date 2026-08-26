<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SiteSettings\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The single-record settings form: one field per `SiteSetting::KNOWN_KEYS`
 * row, with per-key validation. Fields bind `data.*` state paths (the Page's
 * `$data` property) — see `Pages\EditSiteSettings` for why.
 */
final class SiteSettingsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Jam layanan')->schema([
                TextInput::make('data.service_hours')
                    ->label('Jam layanan')
                    ->placeholder('Senin–Jumat 08.00–17.00 WIB')
                    ->maxLength(120),
            ]),
            Section::make('Kontak dukungan')->schema([
                TextInput::make('data.support_phone')->label('Telepon')->maxLength(40),
                TextInput::make('data.support_whatsapp')->label('WhatsApp')->maxLength(40),
                TextInput::make('data.support_email')->label('Email')->email()->maxLength(120),
            ]),
            Section::make('Identitas perusahaan')->schema([
                TextInput::make('data.company_name')->label('Nama perusahaan')->maxLength(191),
                TextInput::make('data.company_address')->label('Alamat terdaftar')->maxLength(255),
                TextInput::make('data.company_nib')
                    ->label('NIB (Nomor Induk Berusaha)')
                    ->maxLength(60)
                    ->helperText('Kosongkan jika belum ada — baris NIB tidak ditampilkan di halaman legal sampai diisi.'),
            ])->description('Ditampilkan di footer dan halaman legal (Kebijakan Privasi, Syarat & Ketentuan).'),
            Section::make('Tinjauan hukum')->schema([
                TextInput::make('data.legal_review_note')
                    ->label('Konfirmasi tinjauan hukum')
                    ->maxLength(255)
                    ->helperText('Kosongkan agar halaman legal tetap menampilkan label "draf awal". Isi setelah tinjauan hukum resmi selesai, mis. "Ditinjau 1 Sep 2026 oleh [nama firma hukum]" — teks ini langsung menggantikan label draf di /privasi dan /syarat-ketentuan tanpa perlu deploy kode.'),
            ]),
            Section::make('Entitas & pemrosesan pembayaran')->schema([
                TextInput::make('data.marketplace_badan_usaha_ref')->label('Ref badan usaha marketplace')->maxLength(120),
                TextInput::make('data.payment_merchant_ref')->label('Ref merchant pembayaran')->maxLength(120),
                TextInput::make('data.payment_badan_usaha_ref')->label('Ref badan usaha pembayaran')->maxLength(120),
            ])->description('Identitas non-rahasia (FIN-DEC). Kredensial tetap di lingkungan (env).'),
            Section::make('Rekening transfer manual')->schema([
                TextInput::make('data.bank_transfer_bank_name')->label('Nama bank')->maxLength(120),
                TextInput::make('data.bank_transfer_account_number')->label('Nomor rekening')->maxLength(60),
                TextInput::make('data.bank_transfer_account_holder')->label('Nama pemilik rekening')->maxLength(191),
            ])->description('Rekening tujuan yang ditampilkan pada kartu "Pembayaran Manual" di wizard pemesanan. Kosongkan salah satu untuk menyembunyikan seluruh rekening — jangan isi sebagian, karena pembayar tidak dapat menggunakan rekening yang tidak lengkap.'),
        ]);
    }
}
