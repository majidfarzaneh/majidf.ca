<?php

namespace App\Services;

use App\Models\UptimeCheck;
use Illuminate\Support\Collection;

class UptimeReport
{
    /**
     * Daily uptime for the last N days across the given services.
     *
     * @param  Collection<int, int>  $serviceIds
     * @return array{percent: float|null, days: list<array{date: string, percent: float|null, state: string}>, up: bool|null}
     */
    public function forServices(Collection $serviceIds, int $days = 30): array
    {
        if ($serviceIds->isEmpty()) {
            return ['percent' => null, 'days' => [], 'up' => null];
        }

        $from = today()->subDays($days - 1);

        $rows = UptimeCheck::query()
            ->whereIn('service_id', $serviceIds)
            ->where('checked_at', '>=', $from)
            ->selectRaw('DATE(checked_at) as day, COUNT(*) as total, SUM(CASE WHEN is_up THEN 1 ELSE 0 END) as up')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $row = $rows->get($date);
            $percent = $row ? round($row->up / $row->total * 100, 2) : null;
            $series[] = [
                'date' => $date,
                'percent' => $percent,
                'state' => match (true) {
                    $percent === null => 'none',
                    $percent >= 99.99 => 'up',
                    $percent >= 90 => 'degraded',
                    default => 'down',
                },
            ];
        }

        $total = $rows->sum('total');
        $latest = UptimeCheck::query()->whereIn('service_id', $serviceIds)
            ->whereIn('id', UptimeCheck::query()->whereIn('service_id', $serviceIds)->selectRaw('MAX(id)')->groupBy('service_id'))
            ->get();

        return [
            'percent' => $total ? round($rows->sum('up') / $total * 100, 2) : null,
            'days' => $series,
            'up' => $latest->isEmpty() ? null : $latest->every(fn ($c) => $c->is_up),
        ];
    }
}
