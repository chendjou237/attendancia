<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date with no time component, stored as a plain "Y-m-d"
 * string on every database engine.
 *
 * Laravel's built-in `date` cast serialises through the connection
 * grammar's format ("Y-m-d H:i:s"), so a date attribute reaches the
 * driver as "2026-08-19 00:00:00". MySQL hides that — a real DATE
 * column discards the time part on write — but SQLite stores dates as
 * text and keeps it verbatim, so the same row compares differently on
 * the two engines. That divergence is why every date comparison in
 * this codebase used to be whereDate(): it forced a date() extraction
 * on the column so both engines agreed.
 *
 * The cost was paid in production. date(`date`) >= ? is not sargable,
 * so MySQL could not use period_results_natural_key or
 * period_results_current_lookup and fell back to a full scan on the
 * engine's hottest table.
 *
 * Canonicalising here instead means the stored value is identical on
 * both engines, so a plain where('date', '>=', '2026-08-19') is
 * correct everywhere — a native DATE comparison on MySQL that uses the
 * indexes, and an ISO-8601 string comparison on SQLite, which sorts
 * correctly because the format is fixed-width and zero-padded.
 *
 * Returns a mutable Carbon, exactly as the `date` cast did, so call
 * sites keep working unchanged.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    /**
     * Accepts anything Carbon can parse — a "Y-m-d" string, a full
     * datetime string, a DateTimeInterface — and always stores the
     * date part alone.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
