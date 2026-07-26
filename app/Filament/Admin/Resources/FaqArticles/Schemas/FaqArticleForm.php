<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles\Schemas;

use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Create/edit form for `FaqArticleResource` — fields per the batch brief:
 * category select, title, slug, summary, body, sort order, related
 * articles. Submission is NOT wired to Filament's default
 * Eloquent-model-save behaviour — see `Pages\CreateFaqArticle::
 * handleRecordCreation()` and `Pages\EditFaqArticle::handleRecordUpdate()`,
 * which read `$data` produced by this schema and call
 * `CreateFaqArticleDraft`/`UpdateFaqArticleContent` instead. That is also
 * why `category_id` and `related_article_ids` deliberately do NOT use
 * `Select::relationship()` / a relation-manager-style binding — those
 * bindings hook into Filament's own `saveRelationships()`/model-save path,
 * which this Resource intentionally bypasses in favour of routing every
 * write through the Domain Actions (`app/Domain/README.md`'s binding rule).
 * Both fields are plain, unbound inputs; the override methods above extract
 * their raw values from `$data` themselves.
 */
final class FaqArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('category_id')
                    ->label('Kategori')
                    ->options(fn (): array => FaqCategory::query()->orderedForDisplay()->pluck('name', 'id')->all())
                    ->required()
                    ->native(false)
                    ->searchable(),

                TextInput::make('sort_order')
                    ->label('Urutan tampil')
                    ->numeric()
                    ->minValue(1)
                    ->helperText(
                        'Kosongkan saat membuat artikel baru agar otomatis diletakkan di akhir urutan '
                        .'kategori. Mengubah nilai ini secara manual tidak menjamin urutan berurutan tanpa '
                        .'celah terhadap artikel lain di kategori yang sama -- gunakan aksi "Naikkan '
                        .'urutan"/"Turunkan urutan" pada tabel daftar untuk penataan ulang yang terjamin '
                        .'(App\Domain\Faq\Actions\ReorderFaqArticles).'
                    ),

                TextInput::make('title')
                    ->label('Judul')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                        // Auto-generate the slug from the title, but ONLY on
                        // create -- editing an already-published article's
                        // title must never silently rewrite its slug (that
                        // would change the article's existing public URL
                        // out from under any inbound link). `$operation` is
                        // Filament's own schema-context value ('create' /
                        // 'edit'), injected by parameter name — verified
                        // against `Filament\Schemas\Components\Component::
                        // evaluate()` (vendor source: `'context', 'operation'
                        // => [$this->getContainer()->getOperation()]`).
                        if ($operation !== 'create') {
                            return;
                        }

                        $set('slug', Str::slug((string) $state));
                    })
                    ->columnSpanFull(),

                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(table: 'faq_articles', column: 'slug', ignoreRecord: true)
                    ->helperText(
                        'Dibuat otomatis dari judul saat artikel dibuat; boleh diubah manual. Mengubah slug '
                        .'artikel yang sudah dipublikasikan akan mengubah URL publiknya.'
                    ),

                Textarea::make('summary')
                    ->label('Ringkasan')
                    ->required()
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),

                Textarea::make('body')
                    ->label('Isi')
                    ->required()
                    ->rows(10)
                    ->columnSpanFull(),

                Select::make('related_article_ids')
                    ->label('Artikel terkait')
                    ->multiple()
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->options(function (?FaqArticle $record): array {
                        // Across ALL categories, not just the current one --
                        // documented choice (batch brief left this open):
                        // `faq-catalog.md`'s "related articles" concept is
                        // not documented as category-scoped, and
                        // `FaqArticle::relatedArticles()` itself places no
                        // such constraint on the pivot.
                        return FaqArticle::forAdmin()
                            ->when($record, fn ($query) => $query->where('id', '!=', $record->getKey()))
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all();
                    })
                    ->helperText(
                        'Menampilkan artikel lain (dari kategori mana pun) sebagai rujukan terkait. Hubungan '
                        .'ini searah -- menandai artikel A terkait dengan B tidak otomatis menandai B terkait '
                        .'dengan A (App\Domain\Faq\Models\FaqArticle::relatedArticles()).'
                    )
                    ->columnSpanFull(),
            ]);
    }
}
