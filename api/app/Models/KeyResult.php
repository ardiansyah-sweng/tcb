<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\UsesTimestampTz;
use Database\Factories\KeyResultFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class KeyResult extends Model
{
    /** @use HasFactory<KeyResultFactory> */
    use BelongsToOwner, HasFactory, UsesTimestampTz;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'start_value' => 'float',
            'target_value' => 'float',
            'current_value' => 'float',
        ];
    }

    /**
     * Add `progress` (0..1) from the key_result_progress view.
     *
     * @param  Builder<KeyResult>  $query
     */
    #[Scope]
    protected function withProgress(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        $query->addSelect(['progress' => DB::table('key_result_progress')
            ->select('progress')
            ->whereColumn('key_result_progress.key_result_id', $query->qualifyColumn('id')),
        ])->withCasts(['progress' => 'float']);
    }

    /**
     * Add one `tasks_<state>_count` column per task state (soft-deleted
     * tasks excluded). Read them as an array with taskCounts().
     *
     * @param  Builder<KeyResult>  $query
     */
    #[Scope]
    protected function withTaskCounts(Builder $query): void
    {
        $query->withCount(array_combine(
            array_map(fn (string $state) => "tasks as tasks_{$state}_count", Task::STATES),
            array_map(fn (string $state) => fn (Builder $tasks) => $tasks->where('state', $state), Task::STATES),
        ));
    }

    /**
     * Task count per state, every state present (0 when none).
     *
     * @return array<string, int>
     */
    public function taskCounts(): array
    {
        $counts = [];
        foreach (Task::STATES as $state) {
            $counts[$state] = (int) $this->getAttribute("tasks_{$state}_count");
        }

        return $counts;
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
