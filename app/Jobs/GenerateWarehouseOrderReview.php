<?php

namespace App\Jobs;

use App\Services\Statistics\WarehouseOrderReviewRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateWarehouseOrderReview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $exportLogId) {}

    public function handle(WarehouseOrderReviewRunService $service): void
    {
        $service->generate($this->exportLogId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(WarehouseOrderReviewRunService::class)->fail($this->exportLogId);
    }
}
