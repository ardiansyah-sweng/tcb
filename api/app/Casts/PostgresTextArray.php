<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a PostgreSQL text[] column to a PHP list of strings and back.
 *
 * @implements CastsAttributes<list<string|null>|null, iterable<string|null>|null>
 */
class PostgresTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : self::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $items = [];
        foreach ($value as $item) {
            $items[] = $item === null
                ? 'NULL'
                : '"'.addcslashes((string) $item, '"\\').'"';
        }

        return '{'.implode(',', $items).'}';
    }

    /**
     * Parse a one-dimensional array literal such as {a,"b c","d \"e\"",NULL}.
     *
     * @return list<string|null>
     */
    public static function parse(string $literal): array
    {
        $inner = substr($literal, 1, -1);
        if ($inner === '') {
            return [];
        }

        $items = [];
        $buffer = '';
        $quoted = false;
        $wasQuoted = false;
        $length = strlen($inner);

        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];

            if ($quoted && $char === '\\') {
                $buffer .= $inner[++$i];
            } elseif ($char === '"') {
                $quoted = ! $quoted;
                $wasQuoted = true;
            } elseif ($char === ',' && ! $quoted) {
                $items[] = (! $wasQuoted && $buffer === 'NULL') ? null : $buffer;
                $buffer = '';
                $wasQuoted = false;
            } else {
                $buffer .= $char;
            }
        }
        $items[] = (! $wasQuoted && $buffer === 'NULL') ? null : $buffer;

        return $items;
    }
}
