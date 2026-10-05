<?php

namespace App\Jobs;

use App\Services\ModerationRunner;
use App\Services\RunOptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Run started from the admin. Picked up by the queue worker that the
 * scheduler starts every minute.
 */
class RunModerationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $options  RunOptions::toArray()
     */
    public function __construct(public array $options) {}

    public function handle(ModerationRunner $runner): void
    {
        $runner->run(RunOptions::fromArray($this->options));
    }
}
