<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Models;

use App\Platform\DocumentVault\Exceptions\SignedUrlGrantImmutableException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Blocks Eloquent bulk mutation methods, which otherwise bypass model
 * instance guards. `SignedUrlGrant::consume()` deliberately uses the base
 * query builder for its one conditional `consumed_at` transition.
 *
 * @extends Builder<SignedUrlGrant>
 */
final class SignedUrlGrantQueryBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw SignedUrlGrantImmutableException::forOperation('update');
    }

    public function delete(): mixed
    {
        throw SignedUrlGrantImmutableException::forOperation('delete');
    }
}
