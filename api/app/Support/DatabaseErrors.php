<?php

namespace App\Support;

use App\Http\Requests\StoreTaskRequest;
use Illuminate\Database\QueryException;

/**
 * Maps rule violations raised by the database (the last guard, CLAUDE.md
 * rule 3) to the API's ValidationErrorBody, so a request that slips past
 * application validation gets a friendly 422 instead of a 500.
 *
 * R1-08 covers the unit progress CHECKs; R1-09 extends this with the other
 * chk_* constraints and the invalid_transition / wip_full hints.
 */
final class DatabaseErrors
{
    private const CHECK_VIOLATION = '23514';

    /** CHECK constraint => [field, message]. */
    private const CHECKS = [
        'chk_tasks_unit_le_total' => ['unit_done', 'Jumlah selesai tidak boleh melebihi total satuan.'],
        'chk_tasks_unit_needs_total' => ['unit_done', StoreTaskRequest::NEEDS_TOTAL_MESSAGE],
    ];

    /**
     * @return array{message: string, errors: array<string, array<int, string>>}|null
     */
    public static function validationBody(QueryException $e): ?array
    {
        if ($e->getCode() !== self::CHECK_VIOLATION) {
            return null;
        }

        foreach (self::CHECKS as $constraint => [$field, $message]) {
            if (str_contains($e->getMessage(), "\"{$constraint}\"")) {
                return ['message' => $message, 'errors' => [$field => [$message]]];
            }
        }

        return null;
    }
}
