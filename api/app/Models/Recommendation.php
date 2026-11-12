<?php

namespace App\Models;

use App\Casts\PostgresTextArray;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\UsesTimestampTz;
use Illuminate\Database\Eloquent\Model;

/** One ranked entry of a recommendation batch, and the user's decision on it. */
class Recommendation extends Model
{
    use BelongsToOwner, BelongsToTask, UsesTimestampTz;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'score' => 'float',
            'breakdown' => 'array',
            'reasons' => PostgresTextArray::class,
            'generated_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
