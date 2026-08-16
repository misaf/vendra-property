<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BuildsDailyTrend
{
    /**
     * Per-day record counts over the trailing window, oldest day first, for use
     * as a stat sparkline.
     *
     * @param  Builder<*>  $query
     * @return list<float>
     */
    protected function dailyTrend(Builder $query, string $column = 'created_at', int $days = 7): array
    {
        $counts = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = now()->subDays($offset);

            $counts[] = (float) (clone $query)
                ->whereBetween($column, [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->count();
        }

        return $counts;
    }
}
