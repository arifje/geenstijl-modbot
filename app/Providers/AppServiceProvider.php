<?php

namespace App\Providers;

use App\Mail\AhaSendTransport;
use App\Services\Analysis\LanguageModel;
use App\Services\Analysis\LanguageModelFactory;
use App\Services\Analysis\PromptRepository;
use App\Services\Reporting\AhaSendMailer;
use App\Support\Heartbeat;
use App\Support\Settings;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromptRepository::class);

        // Resolved per run, so a provider switched in the admin is used by the next run.
        $this->app->bind(LanguageModel::class, fn () => app(LanguageModelFactory::class)->make());
    }

    public function boot(): void
    {
        Settings::apply();

        // Behind a tunnel or proxy (Tailscale Funnel, Cloudflare Tunnel, ingress)
        // the app may see plain http; generate https links when APP_URL is https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // MAIL_MAILER=ahasend sends Laravel mail (login codes) through the AhaSend API.
        Mail::extend('ahasend', fn () => new AhaSendTransport(app(AhaSendMailer::class)));

        $this->logQueueAndScheduler();
    }

    /**
     * Queue and scheduler activity for the Logs page in the admin.
     */
    private function logQueueAndScheduler(): void
    {
        Queue::before(fn (JobProcessing $e) => Log::info("Job started: {$e->job->resolveName()}", ['category' => 'queue']));
        Queue::after(fn (JobProcessed $e) => Log::info("Job finished: {$e->job->resolveName()}", ['category' => 'queue']));
        Queue::failing(fn (JobFailed $e) => Log::error("Job failed: {$e->job->resolveName()}", ['category' => 'queue', 'exception' => $e->exception]));

        // "Last seen" for System → Queue. The worker task is due every minute,
        // so the scheduler starts or skips (still running) something each minute.
        Queue::looping(function () {
            Heartbeat::beat('worker');
        });
        Queue::before(fn () => Heartbeat::beat('worker'));
        Event::listen([ScheduledTaskStarting::class, ScheduledTaskSkipped::class], fn () => Heartbeat::beat('scheduler'));

        // The queue worker is started every minute; only log it when it fails.
        $isWorker = fn ($task) => str_contains((string) $task->command, 'queue:work');

        Event::listen(ScheduledTaskStarting::class, fn ($e) => $isWorker($e->task) ? null
            : Log::info('Scheduled task started: '.$this->taskName($e->task), ['category' => 'scheduler']));
        // Background tasks (runInBackground) report "finished" as soon as they are
        // started, without an exit code; their result arrives with
        // ScheduledBackgroundTaskFinished.
        Event::listen(ScheduledTaskFinished::class, fn ($e) => $e->task->exitCode === null ? null
            : $this->logTaskResult($e->task, $e->runtime));
        Event::listen(ScheduledBackgroundTaskFinished::class, fn ($e) => $this->logTaskResult($e->task));
        Event::listen(ScheduledTaskFailed::class, fn ($e) => Log::error('Scheduled task failed: '.$this->taskName($e->task), [
            'category' => 'scheduler',
            'exception' => $e->exception,
        ]));
    }

    private function logTaskResult(object $task, ?float $seconds = null): void
    {
        $failed = $task->exitCode !== 0;

        // The queue worker runs every minute; only log it when it fails.
        if (! $failed && str_contains((string) $task->command, 'queue:work')) {
            return;
        }

        Log::log($failed ? 'error' : 'info', ($failed ? 'Scheduled task failed: ' : 'Scheduled task finished: ').$this->taskName($task), array_filter([
            'category' => 'scheduler',
            'exit_code' => $task->exitCode,
            'seconds' => $seconds !== null ? round($seconds, 1) : null,
        ], fn ($value) => $value !== null));
    }

    private function taskName(object $task): string
    {
        return $task->description ?: trim(preg_replace("/^.*artisan'?\\s*/", '', (string) $task->command));
    }
}
