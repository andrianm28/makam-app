<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DemoDataBatch extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'demo_data_batches';

    protected $fillable = ['id', 'batch_id', 'summary', 'created_at'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
