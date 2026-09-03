<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * A single shared daily budget for the two paid APIs this demo calls
 * (Gemini, RapidAPI JSearch). Once a budget is spent for the day, callers
 * fall back to the bundled sample data instead of erroring out.
 */
class DemoBudget
{
    public static function consumeGemini(): bool
    {
        return self::consume('gemini', (int) config('demo.gemini_daily_budget'));
    }

    public static function consumeJsearch(): bool
    {
        return self::consume('jsearch', (int) config('demo.jsearch_daily_budget'));
    }

    private static function consume(string $service, int $dailyLimit): bool
    {
        if ($dailyLimit <= 0) {
            return true; // budget disabled
        }

        $key = "demo-budget:{$service}:" . now()->format('Y-m-d');
        $used = (int) Cache::get($key, 0);

        if ($used >= $dailyLimit) {
            return false;
        }

        Cache::put($key, $used + 1, now()->endOfDay());

        return true;
    }
}
