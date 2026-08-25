<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Platform\IdentityAccess\Scopes\Concerns\HasScopeAssignments;
use App\Platform\IdentityAccess\Scopes\Contracts\ScopeAssignable;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only fixture proving `HasScopeAssignments`/`ScopeAssignmentGlobalScope`
 * work against an arbitrary Eloquent model, per the batch brief: "prove the
 * mechanism works with a minimal test-only fixture model ... not a real
 * domain model, not shipped as application code that other specs would need
 * to reconcile with later."
 *
 * Deliberately lives under `tests/Fixtures/`, not `app/**` — it is never
 * autoloaded in production (PSR-4 `Tests\` maps to `tests/`, per
 * `composer.json`'s `autoload-dev`), and its backing table
 * (`scoped_test_models`) is created ad hoc by
 * `tests/Fixtures/CreatesScopedTestModelTable.php` inside test `setUp()`,
 * not by a shipped `database/migrations/*.php` file — so nothing here ships
 * to any real environment.
 *
 * Uses `ScopeEntityType::CEMETERY` as its entity type only because SOME
 * known type has to be picked for the trait's contract to be satisfied;
 * this is not a claim that a real `Cemetery` model exists (it does not —
 * see this batch's report).
 */
final class ScopedTestModel extends Model implements ScopeAssignable
{
    use HasScopeAssignments;

    protected $table = 'scoped_test_models';

    /**
     * @var list<string>
     */
    protected $fillable = ['name'];

    public function scopeAssignmentEntityType(): string
    {
        return ScopeEntityType::CEMETERY;
    }
}
