<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\UsesTimestampTz;
use Database\Factories\ObjectiveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Objective extends Model
{
    /** @use HasFactory<ObjectiveFactory> */
    use BelongsToOwner, HasFactory, UsesTimestampTz;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    /** @return HasMany<KeyResult, $this> */
    public function keyResults(): HasMany
    {
        return $this->hasMany(KeyResult::class);
    }

    /** @return HasManyThrough<Task, KeyResult, $this> */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, KeyResult::class);
    }
}
