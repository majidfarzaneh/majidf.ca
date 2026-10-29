<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Models\UptimeCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('uptime:check')]
#[Description('Ping every monitored service and record whether it is up')]
class CheckUptime extends Command
{
    public function handle(): void
    {
        $services = Service::query()->whereNotNull('monitor_url')
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::InDevelopment])->get();

        foreach ($services->chunk(10) as $chunk) {
            $started = microtime(true);
            $responses = Http::pool(fn (Pool $pool) => $chunk->map(
                fn (Service $s) => $pool->as((string) $s->id)->timeout(10)->connectTimeout(5)
                    ->withUserAgent('MajidPortalUptime/1.0')->get($s->monitor_url)
            )->all());
            $elapsed = (int) ((microtime(true) - $started) * 1000);

            foreach ($chunk as $service) {
                $response = $responses[(string) $service->id] ?? null;
                $ok = ! $response instanceof Throwable && $response?->status() < 500 && $response?->status() > 0;
                UptimeCheck::create([
                    'service_id' => $service->id,
                    'is_up' => $ok,
                    'status_code' => $response instanceof Throwable ? null : $response?->status(),
                    'response_ms' => $elapsed,
                    'checked_at' => now(),
                ]);
            }
        }

        UptimeCheck::query()->where('checked_at', '<', now()->subDays(90))->delete();
        $this->info("Checked {$services->count()} services.");
    }
}
