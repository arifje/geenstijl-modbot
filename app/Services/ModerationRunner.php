<?php

namespace App\Services;

use App\Models\ModerationRun;
use App\Models\Schedule;
use App\Services\Reporting\AhaSendMailer;
use App\Services\Reporting\SlackNotifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs the pipeline and delivers the report. Shared by the CLI, the
 * scheduler and the admin.
 */
class ModerationRunner
{
    public function __construct(
        private ModerationPipeline $pipeline,
        private SlackNotifier $slack,
        private AhaSendMailer $mailer,
    ) {}

    /**
     * @param  callable(string): void|null  $progress
     *
     * @throws \Throwable when the pipeline itself fails
     */
    public function run(RunOptions $options, ?callable $progress = null): ModerationRun
    {
        $output = $progress ?? fn (string $message) => null;
        // Progress also goes to the log (admin → Logs, category "run").
        $progress = function (string $message) use ($output) {
            Log::info($message, ['category' => 'run']);
            $output($message);
        };

        $queued = $options->runId ? ModerationRun::find($options->runId) : null;

        if ($options->runId && $queued?->status !== 'queued') {
            $progress('Run was cancelled or already handled; skipping.');

            return $queued ?? throw new \RuntimeException("Run {$options->runId} no longer exists.");
        }

        // Two runs at the same time would analyse the same comments twice.
        $lock = Cache::lock('modbot:run', 1800);

        try {
            $lock->block(600);
        } catch (LockTimeoutException) {
            ModerationRun::whereKey($options->runId)->where('status', 'queued')
                ->update(['status' => 'failed', 'error' => 'Another run was still in progress.', 'finished_at' => now()]);

            throw new \RuntimeException('Another run is still in progress.');
        }

        try {
            // Claim the queued run now that we hold the lock: it may have been
            // cancelled while we were waiting for another run to finish.
            if ($options->runId && ! ModerationRun::whereKey($options->runId)->where('status', 'queued')->update(['status' => 'running'])) {
                $progress('Run was cancelled while waiting; skipping.');

                return ModerationRun::findOrFail($options->runId);
            }

            if ($options->scheduleId) {
                Schedule::whereKey($options->scheduleId)->update(['last_run_at' => now()]);
            }

            try {
                $run = $this->pipeline->run($options, $progress);
            } catch (\Throwable $e) {
                Log::error('Run failed: '.$e->getMessage(), ['category' => 'run', 'exception' => $e]);
                $this->notifyFailure($options, $e);

                throw $e;
            }

            Log::info("Run #{$run->id} {$run->status}: {$run->analyzed_count} analysed, {$run->flagged_count} flagged", [
                'category' => 'run',
                'run_id' => $run->id,
                'model' => $run->model,
                'input_tokens' => $run->input_tokens,
                'output_tokens' => $run->output_tokens,
            ]);

            if (! $options->dryRun) {
                $this->deliver($run, $options->sendSlack, $options->sendEmail, $options->slackChannel, $progress);
            }

            return $run;
        } finally {
            $lock->release();
        }
    }

    /**
     * Sends the report of a run. Errors are recorded on the run instead of
     * thrown, so one failing channel does not block the other.
     *
     * @param  callable(string): void|null  $progress
     * @return list<string> delivery errors
     */
    public function deliver(ModerationRun $run, bool $slack, bool $email, ?string $channel = null, ?callable $progress = null): array
    {
        $progress ??= fn (string $message) => null;
        $report = $this->pipeline->report($run);
        $errors = [];

        if ($slack && config('modbot.slack.enabled')) {
            try {
                $channel ??= config('modbot.slack.channel');
                $run->update(['slack_channel' => $channel, 'slack_ts' => $this->slack->send($report, $channel)]);
                $progress("Posted report to Slack {$channel}");
            } catch (\Throwable $e) {
                Log::error('Sending the report to Slack failed: '.$e->getMessage(), ['category' => 'run', 'run_id' => $run->id]);
                $errors[] = 'Slack: '.$e->getMessage();
            }
        }

        if ($email && config('modbot.email.enabled')) {
            try {
                $run->update(['email_message_id' => $this->mailer->send($report)]);
                $progress('Sent email report');
            } catch (\Throwable $e) {
                Log::error('Sending the report by email failed: '.$e->getMessage(), ['category' => 'run', 'run_id' => $run->id]);
                $errors[] = 'Email: '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            // Keep earlier problems of the run (e.g. comments that could not be fetched).
            $run->update(['error' => implode("\n", array_filter([$run->error, ...$errors]))]);
        }

        return $errors;
    }

    private function notifyFailure(RunOptions $options, \Throwable $e): void
    {
        if (! config('modbot.slack.enabled') || ! $options->sendSlack || $options->dryRun) {
            return;
        }

        try {
            $this->slack->notice('⚠️ ModBot-run mislukt: '.$e->getMessage(), $options->slackChannel);
        } catch (\Throwable $slackError) {
            report($slackError);
        }
    }
}
