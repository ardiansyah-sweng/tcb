<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\UsesTimestampTz;
use Database\Factories\ObjectiveFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;

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

    /**
     * Add `progress` (0..1) from the objective_progress view.
     *
     * @param  Builder<Objective>  $query
     */
    #[Scope]
    protected function withProgress(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        $query->addSelect(['progress' => DB::table('objective_progress')
            ->select('progress')
            ->whereColumn('objective_progress.objective_id', $query->qualifyColumn('id')),
        ])->withCasts(['progress' => 'float']);
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
