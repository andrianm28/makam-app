<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles;

use App\Domain\Faq\Contracts\FaqAuthorizer;
use App\Domain\Faq\Models\FaqArticle;
use App\Filament\Admin\Resources\FaqArticles\Pages\CreateFaqArticle;
use App\Filament\Admin\Resources\FaqArticles\Pages\EditFaqArticle;
use App\Filament\Admin\Resources\FaqArticles\Pages\ListFaqArticles;
use App\Filament\Admin\Resources\FaqArticles\Pages\ViewFaqArticle;
use App\Filament\Admin\Resources\FaqArticles\Schemas\FaqArticleForm;
use App\Filament\Admin\Resources\FaqArticles\Schemas\FaqArticleInfolist;
use App\Filament\Admin\Resources\FaqArticles\Tables\FaqArticlesTable;
use App\Platform\IdentityAccess\ActorContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The first real Filament Resource in this repository — `app/Filament/
 * Admin/` previously held only a `.gitkeep`. Manages `FaqArticle`
 * (`admin-operations` AC6: "manage FAQ category, article, draft, preview,
 * publish/unpublish, and ordering"; requirements.md AC5: "create, preview,
 * publish, unpublish, reorder, and version FAQ articles").
 *
 * No FaqCategory resource exists here, and none is planned — Batch 4.1's
 * own doc block on `App\Domain\Faq\Models\FaqCategory` is explicit:
 * categories are seed data for this MVP ("this batch builds no 'create
 * category'/'delete category' write action for exactly that reason"),
 * matching `AGENTS.md` §FAQ: "Seed and preserve six required categories."
 *
 * ---------------------------------------------------------------------------
 * Directory layout — verified against a real installed Filament 5, not
 * assumed from an older version's convention
 * ---------------------------------------------------------------------------
 * Filament 3/4-era projects commonly used a flat `XResource.php` +
 * `XResource/Pages/*.php` layout. Filament 5 changed this: traced against
 * `filament/filament` v5.7.3 (this repo's own `composer.lock` pin) at
 * `/home/ubuntu/platform-galang-dana-app/vendor/filament/filament` —
 * specifically `Filament\Commands\MakeResourceCommand::configureLocation()`
 * and `configurePageRoutes()`, and the file-generator classes it calls
 * (`Commands\FileGenerators\Resources\ResourceClassGenerator`,
 * `...\Schemas\ResourceFormSchemaClassGenerator`,
 * `...\Schemas\ResourceTableClassGenerator`) — the current default (a
 * non-"simple", non-embedded resource, which is what `make:filament-
 * resource` produces without `--simple`/`--embed-*`) generates:
 *
 *   Resources/FaqArticles/FaqArticleResource.php   (this file)
 *   Resources/FaqArticles/Schemas/FaqArticleForm.php
 *   Resources/FaqArticles/Schemas/FaqArticleInfolist.php
 *   Resources/FaqArticles/Tables/FaqArticlesTable.php
 *   Resources/FaqArticles/Pages/ListFaqArticles.php
 *   Resources/FaqArticles/Pages/CreateFaqArticle.php
 *   Resources/FaqArticles/Pages/EditFaqArticle.php
 *   Resources/FaqArticles/Pages/ViewFaqArticle.php
 *
 * This Resource follows that layout exactly (plural-model-named directory,
 * `Schemas/`+`Tables/` split rather than embedding `form()`/`table()`
 * bodies directly in this class). One deliberate addition beyond what
 * `make:filament-resource` would name: `FaqArticleStatusBadge.php`, a small
 * local status-badge helper this Resource's own table/infolist need — see
 * that class's own doc block for why it exists instead of reusing
 * `App\Support\Design\StatusIntent`.
 *
 * `getPages()`'s `Page::route()` static-factory call shape and the
 * `handleRecordCreation()`/`handleRecordUpdate()` override hook names used
 * in `Pages\CreateFaqArticle`/`Pages\EditFaqArticle` were verified the same
 * way, against `Filament\Resources\Pages\CreateRecord`/`EditRecord`'s real
 * source in the same installed package.
 */
final class FaqArticleResource extends Resource
{
    protected static ?string $model = FaqArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * Every authorization question Filament asks about this Resource, answered
     * in one place by `Domain\Faq\Contracts\FaqAuthorizer`.
     *
     * ---------------------------------------------------------------------------
     * Why this one method and not `canViewAny()`/`canCreate()`/`canEdit()`
     * ---------------------------------------------------------------------------
     * Traced against the installed `filament/filament` v5.7.3 source, not
     * assumed: `Filament\Resources\Resource\Concerns\HasAuthorization` derives
     * EVERY public predicate from this single method —
     * `getViewAnyAuthorizationResponse()` … `getReorderAuthorizationResponse()`
     * each call `getAuthorizationResponse($ability, $record)`, and every
     * `can*()` / `authorize*()` is a one-line `->allowed()` / `->authorize()`
     * on top of those. Overriding it here therefore covers `canViewAny()`
     * (the list page, via `Resource::canAccess()` and
     * `Pages\Concerns\CanAuthorizeResourceAccess`'s mount AND hydrate hooks),
     * `canCreate()`, `canEdit()`, `canView()`, and the delete/restore/reorder
     * abilities this Resource does not expose yet.
     *
     * Overriding the four named predicates individually instead would state one
     * decision in four places and, worse, would silently MISS the table's
     * built-in `ViewAction`/`EditAction`: `Filament\Resources\Pages\Page
     * ::getDefaultActionAuthorizationResponse()` routes those to
     * `get*AuthorizationResponse()` directly, never through `can*()`. A
     * `canEdit()`-only override would have left the Edit row action open while
     * looking closed.
     *
     * ---------------------------------------------------------------------------
     * One decision for all abilities, deliberately
     * ---------------------------------------------------------------------------
     * `$action` and `$record` are accepted (the parent's signature) and not
     * branched on. There is no per-ability or per-record distinction to draw:
     * `docs/security/rbac-matrix.md` has no FAQ row at all, so a
     * read-vs-write or own-vs-others split here would be invented, not
     * discovered — and every ability this Resource exposes writes to or reveals
     * unpublished public-facing content. The default arm being a refusal is the
     * point: an ability name nobody has considered yet fails closed.
     *
     * Note that `parent::getAuthorizationResponse()` is deliberately NOT called
     * and not fallen back to. It resolves a Laravel model policy through
     * `Filament\get_authorization_response()`; no `FaqArticlePolicy` exists and
     * none may be added (see `Domain\Faq\Contracts\FaqAuthorizer`'s doc block on
     * why this repository has no `app/Policies/`), so any fallback path would be
     * a second authorization idiom for the same records.
     */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $mayManage = app(FaqAuthorizer::class)->canManage(app(ActorContext::class));

        return $mayManage
            ? Response::allow()
            : Response::deny('Anda tidak berwenang mengelola konten FAQ.');
    }

    public static function form(Schema $schema): Schema
    {
        return FaqArticleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FaqArticleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FaqArticlesTable::configure($table);
    }

    /**
     * Routes through `FaqArticle::forAdmin()` rather than a bare
     * `parent::getEloquentQuery()` — functionally identical today
     * (`forAdmin(): Builder { return self::query(); }`, and this panel has
     * no tenancy for `parent::getEloquentQuery()`'s tenant-scope branch to
     * differ on), but `forAdmin()` is that model's own explicitly-named
     * "this call site intentionally sees every article, including drafts
     * and unpublished ones" escape hatch (see its class-level AC6 doc
     * block, point 3). This Resource's entire purpose IS that admin escape
     * hatch, so naming it explicitly here keeps the grep-ability that
     * model doc block asks every admin call site to preserve.
     */
    public static function getEloquentQuery(): Builder
    {
        return FaqArticle::forAdmin()->with('category');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqArticles::route('/'),
            'create' => CreateFaqArticle::route('/create'),
            'view' => ViewFaqArticle::route('/{record}'),
            'edit' => EditFaqArticle::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'artikel FAQ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Artikel FAQ';
    }

    public static function getNavigationLabel(): string
    {
        return 'FAQ';
    }
}
