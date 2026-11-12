<?php

namespace App\Http\Requests\Concerns;

use App\Models\Task;
use App\Support\ProgressUnit;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Unit progress (FR-23) for StoreTaskRequest and UpdateTaskRequest.
 *
 * The cross-field checks run on the effective values: the request value when
 * the field is sent, otherwise the stored one. They mirror the CHECK
 * constraints chk_tasks_unit_needs_total and chk_tasks_unit_le_total so the
 * user gets a specific message; the database still enforces both.
 */
trait ValidatesProgressUnit
{
    public const NEEDS_TOTAL_MESSAGE = 'Isi total satuan dulu sebelum mencatat jumlah selesai.';

    /** @var array<string, string> */
    public const PROGRESS_UNIT_MESSAGES = [
        'unit_total.numeric' => 'Total satuan harus berupa angka.',
        'unit_total.gt' => 'Total satuan harus lebih dari 0.',
        'unit_done.numeric' => 'Jumlah selesai harus berupa angka.',
        'unit_done.min' => 'Jumlah selesai tidak boleh negatif.',
        'unit_name.string' => 'Nama satuan harus berupa teks.',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function progressUnitRules(): array
    {
        return [
            'unit_total' => ['bail', 'nullable', 'numeric', 'gt:0'],
            'unit_done' => ['bail', 'nullable', 'numeric', 'min:0'],
            'unit_name' => ['nullable', 'string'],
        ];
    }

    /** The task being updated, or null when creating. */
    abstract protected function existingTask(): ?Task;

    protected function progressUnitCheck(): Closure
    {
        return function (Validator $validator) {
            $errors = $validator->errors();
            if ($errors->hasAny(['unit_total', 'unit_done'])) {
                return;
            }

            [$total, $done] = $this->effectiveUnits();
            if ($done === null) {
                return;
            }

            if ($total === null) {
                $errors->add('unit_done', self::NEEDS_TOTAL_MESSAGE);
            } elseif ((float) $done > (float) $total) {
                $name = $this->effectiveUnitName() ?? ProgressUnit::DEFAULT_NAME;
                $doneText = ProgressUnit::formatNumber($done);
                $totalText = ProgressUnit::formatNumber($total);

                // Blame the field the user sent; prefer unit_done when both are sent.
                if ($this->exists('unit_done')) {
                    $errors->add('unit_done', "Jumlah selesai ({$doneText}) tidak boleh melebihi total ({$totalText} {$name}).");
                } else {
                    $errors->add('unit_total', "Total ({$totalText} {$name}) tidak boleh kurang dari jumlah yang sudah selesai ({$doneText}).");
                }
            }
        };
    }

    /**
     * Validated attributes with the unit defaults applied:
     * - clearing unit_total removes the whole unit progress;
     * - an empty unit_name is stored as "unit" while a total exists.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withProgressUnitDefaults(array $data): array
    {
        if (array_key_exists('unit_total', $data) && $data['unit_total'] === null) {
            return ['unit_done' => null, 'unit_name' => null] + $data;
        }

        [$total] = $this->effectiveUnits();
        if ($total !== null && blank($this->effectiveUnitName())) {
            $data['unit_name'] = ProgressUnit::DEFAULT_NAME;
        }

        return $data;
    }

    /**
     * @return array{0: mixed, 1: mixed} [unit_total, unit_done]
     */
    private function effectiveUnits(): array
    {
        $task = $this->existingTask();
        $total = $this->exists('unit_total') ? $this->input('unit_total') : $task?->unit_total;

        if ($this->exists('unit_done')) {
            $done = $this->input('unit_done');
        } else {
            // Clearing the total also clears the stored count (withProgressUnitDefaults).
            $done = $this->exists('unit_total') && $total === null ? null : $task?->unit_done;
        }

        return [$total, $done];
    }

    private function effectiveUnitName(): ?string
    {
        $name = $this->exists('unit_name') ? $this->input('unit_name') : $this->existingTask()?->unit_name;

        return is_string($name) ? $name : null;
    }
}
