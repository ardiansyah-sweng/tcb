<?php

namespace App\Support;

/**
 * FR-23 unit progress helpers: the default unit name, the unit name suggested
 * by a task label, and number formatting for user-facing messages.
 */
final class ProgressUnit
{
    public const DEFAULT_NAME = 'unit';

    /** Lower-cased label => suggested unit name (BACKLOG-R1 R1-08). */
    private const SUGGESTIONS = [
        'koreksi ujian' => 'lembar',
        'menulis paper' => 'halaman',
        'mengajar' => 'slide',
    ];

    /**
     * A one-click offer for the client; it is never stored automatically
     * (SRS bagian 8, prinsip input minimal).
     */
    public static function suggestName(?string $label): string
    {
        $key = mb_strtolower(trim((string) $label));

        return self::SUGGESTIONS[$key] ?? self::DEFAULT_NAME;
    }

    /** 22.0 => "22", 40.5 => "40,5" (Indonesian decimal comma). */
    public static function formatNumber(float|int|string $value): string
    {
        return str_replace('.', ',', (string) (float) $value);
    }
}
