<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\Exceptions\SignedUrlGrantImmutableException;
use Illuminate\Database\Query\Builder;

/**
 * Read-capable base query returned by `SignedUrlGrantQueryBuilder::toBase()`.
 * It preserves normal count/pluck/select behavior while making every generic
 * base-query mutation fail closed.
 */
final class SignedUrlGrantBaseQueryBuilder extends Builder
{
    public function __construct(Builder $source)
    {
        parent::__construct($source->connection, $source->grammar, $source->processor);

        foreach (get_object_vars($source) as $property => $value) {
            $this->{$property} = $value;
        }
    }

    public function update(array $values): never
    {
        $this->rejectMutation('update');
    }

    public function updateFrom(array $values): never
    {
        $this->rejectMutation('updateFrom');
    }

    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): never
    {
        $this->rejectMutation('upsert');
    }

    public function insert(array $values): never
    {
        $this->rejectMutation('insert');
    }

    public function insertGetId(array $values, $sequence = null): never
    {
        $this->rejectMutation('insertGetId');
    }

    public function insertOrIgnore(array $values): never
    {
        $this->rejectMutation('insertOrIgnore');
    }

    public function insertOrIgnoreReturning(
        array $values,
        array $returning = ['*'],
        array|string|null $uniqueBy = null,
    ): never {
        $this->rejectMutation('insertOrIgnoreReturning');
    }

    public function insertUsing(array $columns, $query): never
    {
        $this->rejectMutation('insertUsing');
    }

    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        $this->rejectMutation('insertOrIgnoreUsing');
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        $this->rejectMutation('updateOrInsert');
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        $this->rejectMutation('increment');
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        $this->rejectMutation('incrementEach');
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        $this->rejectMutation('decrement');
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        $this->rejectMutation('decrementEach');
    }

    public function delete($id = null): never
    {
        $this->rejectMutation('delete');
    }

    public function truncate(): never
    {
        $this->rejectMutation('truncate');
    }

    private function rejectMutation(string $operation): never
    {
        throw SignedUrlGrantImmutableException::forOperation($operation);
    }
}
