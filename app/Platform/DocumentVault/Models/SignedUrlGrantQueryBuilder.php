<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\Exceptions\SignedUrlGrantImmutableException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Blocks Eloquent bulk mutation methods, which otherwise bypass model
 * instance guards. `toBase()` returns a read-capable base builder whose write
 * methods are also blocked. `SignedUrlGrant::consume()` deliberately uses the
 * parent base query builder for its one conditional `consumed_at` transition.
 *
 * @extends Builder<SignedUrlGrant>
 */
final class SignedUrlGrantQueryBuilder extends Builder
{
    /**
     * @var list<string>
     */
    private const array FORWARDED_MUTATION_METHODS = [
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'insertUsing',
        'updateOrInsert',
    ];

    public function firstOrNew(array $attributes = [], Closure|array $values = []): never
    {
        $this->rejectMutation('firstOrNew');
    }

    public function firstOrCreate(array $attributes = [], Closure|array $values = []): never
    {
        $this->rejectMutation('firstOrCreate');
    }

    public function createOrFirst(array $attributes = [], Closure|array $values = []): never
    {
        $this->rejectMutation('createOrFirst');
    }

    public function updateOrCreate(array $attributes, Closure|array $values = []): never
    {
        $this->rejectMutation('updateOrCreate');
    }

    public function incrementOrCreate(
        array $attributes,
        string $column = 'count',
        $default = 1,
        $step = 1,
        array $extra = [],
    ): never {
        $this->rejectMutation('incrementOrCreate');
    }

    public function create(array $attributes = []): never
    {
        $this->rejectMutation('create');
    }

    public function createQuietly(array $attributes = []): never
    {
        $this->rejectMutation('createQuietly');
    }

    public function forceCreate(array $attributes): never
    {
        $this->rejectMutation('forceCreate');
    }

    public function forceCreateQuietly(array $attributes = []): never
    {
        $this->rejectMutation('forceCreateQuietly');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw SignedUrlGrantImmutableException::forOperation('update');
    }

    public function delete(): mixed
    {
        return $this->rejectMutation('delete');
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        return $this->rejectMutation('upsert');
    }

    public function forceDelete(): mixed
    {
        return $this->rejectMutation('forceDelete');
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        return $this->rejectMutation('increment');
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        return $this->rejectMutation('decrement');
    }

    public function incrementEach(array $columns, array $extra = []): int
    {
        return $this->rejectMutation('incrementEach');
    }

    public function decrementEach(array $columns, array $extra = []): int
    {
        return $this->rejectMutation('decrementEach');
    }

    public function touch($column = null): mixed
    {
        return $this->rejectMutation('touch');
    }

    /**
     * The only controlled write path. It is intentionally narrower than a
     * generic base-query escape hatch: callers can identify one grant and set
     * only its previously-null `consumed_at` value.
     */
    public function consume(int|string $grantId, CarbonImmutable $consumedAt): bool
    {
        $updated = parent::toBase()
            ->where($this->getModel()->getKeyName(), $grantId)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $consumedAt]);

        return $updated === 1;
    }

    /**
     * Generic callers may not escape to the underlying mutation-capable
     * query builder. `consume()` is the only method allowed to call the
     * parent implementation internally.
     */
    public function toBase(): SignedUrlGrantBaseQueryBuilder
    {
        return new SignedUrlGrantBaseQueryBuilder(parent::toBase());
    }

    /**
     * @param  array<mixed>  $parameters
     */
    public function __call($method, $parameters)
    {
        if (in_array($method, self::FORWARDED_MUTATION_METHODS, true)) {
            return $this->rejectMutation($method);
        }

        return parent::__call($method, $parameters);
    }

    private function rejectMutation(string $operation): never
    {
        throw SignedUrlGrantImmutableException::forOperation($operation);
    }
}
