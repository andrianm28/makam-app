<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles\Schemas;

use App\Filament\Admin\Resources\FaqArticles\FaqArticleStatusBadge;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * The "preview" surface requirements.md AC5 asks for, distinct from
 * create/edit. `App\Domain\Faq\Models\FaqArticle`'s own class-level doc
 * block already settled the structural question: "requirements.md AC5's
 * 'preview' capability is satisfied structurally by `Model::forAdmin()`
 * already returning the current row regardless of `publish_state` — a
 * later UI batch renders that as the preview, no extra column/table
 * required." This class + `Pages\ViewFaqArticle` is that later UI batch: a
 * read-only, in-panel rendering of the article's current row — i.e. what a
 * public visitor would see if it were published, viewable regardless of its
 * actual `publish_state` — not a live-embedded public page template (a
 * separate, concurrently-built batch owns `resources/views/livewire/**` and
 * `app/Livewire/Public/**`, out of this batch's file scope).
 */
final class FaqArticleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('category.name')
                    ->label('Kategori'),

                TextEntry::make('publish_state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => FaqArticleStatusBadge::color($state))
                    ->formatStateUsing(fn (string $state): string => FaqArticleStatusBadge::label($state)),

                TextEntry::make('title')
                    ->label('Judul')
                    ->columnSpanFull(),

                TextEntry::make('summary')
                    ->label('Ringkasan')
                    ->columnSpanFull(),

                TextEntry::make('body')
                    ->label('Isi')
                    ->prose()
                    ->columnSpanFull(),

                TextEntry::make('relatedArticles.title')
                    ->label('Artikel terkait')
                    ->listWithLineBreaks()
                    ->placeholder('Tidak ada artikel terkait.')
                    ->columnSpanFull(),

                TextEntry::make('updated_at')
                    ->label('Terakhir diperbarui')
                    ->dateTime(),

                TextEntry::make('published_at')
                    ->label('Terakhir dipublikasikan')
                    ->dateTime()
                    ->placeholder('Belum pernah dipublikasikan.'),
            ]);
    }
}
