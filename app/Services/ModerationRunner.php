<?php

namespace App\Services;

use App\Models\ModerationRun;
use App\Models\Schedule;
use App\Services\Reporting\AhaSendMailer;
use App\Services\Reporting\SlackNotifier;
use App\Support\Settings;
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
                // deliver() writes its own, more detailed log entries.
                $this->deliver($run, $options->sendSlack, $options->sendEmail, $options->slackChannel, $output);
            }

            return $run;
        } finally {
            $lock->release();
        }
    }

    /**
     * Sends the report of a run. Errors are recorded on the run instead of
     * thrown, so one failing channel does not block the other. Every send,
     * skip and failure is logged with what was sent and to whom.
     *
     * @param  callable(string): void|null  $progress
     * @return list<string> delivery errors
     */
    public function deliver(ModerationRun $run, bool $slack, bool $email, ?string $channel = null, ?callable $progress = null): array
    {
        $progress ??= fn (string $message) => null;
        $report = $this->pipeline->report($run);
        $errors = [];
        $context = array_filter([
            'category' => 'run',
            'run_id' => $run->id,
            'flagged' => $run->flagged_count,
            'sent_by' => auth()->user()?->email,
        ], fn ($value) => $value !== null);

        if ($slack && ! config('modbot.slack.enabled')) {
            Log::info("Run #{$run->id}: Slack report not sent, Slack is switched off", $context);
        } elseif ($slack) {
            $channelSource = $channel === null ? Settings::source('slack_channel') : 'chosen for this send';
            $channel ??= config('modbot.slack.channel');
            $slackContext = [...$context, 'channel' => $channel, 'channel_source' => $channelSource];

            try {
                $ts = $this->slack->send($report, $channel);
                $run->update(['slack_channel' => $channel, 'slack_ts' => $ts]);
                Log::info("Run #{$run->id}: report posted to Slack {$channel}", [...$slackContext, 'message_ts' => $ts]);
                $progress("Posted report to Slack {$channel}");
            } catch (\Throwable $e) {
                Log::error("Run #{$run->id}: sending the report to Slack {$channel} failed: ".$e->getMessage(), $slackContext);
                $errors[] = 'Slack: '.$e->getMessage();
            }
        }

        if ($email && ! config('modbot.email.enabled')) {
            Log::info("Run #{$run->id}: email report not sent, email is switched off", $context);
        } elseif ($email) {
            $emailContext = [
                ...$context,
                'recipients' => array_column($this->mailer->recipients((string) config('modbot.email.recipients')), 'email'),
                'recipients_source' => Settings::source('email_recipients'),
            ];

            try {
                $sent = $this->mailer->send($report);
                $run->update(['email_message_id' => $sent['id']]);
                $to = implode(', ', $sent['recipients']);
                Log::info("Run #{$run->id}: email report sent to {$to}".($sent['sandbox'] ? ' (AhaSend sandbox: not delivered)' : ''), [
                    ...$emailContext,
                    'subject' => $sent['subject'],
                    'message_id' => $sent['id'],
                    'sandbox' => $sent['sandbox'],
                ]);
                $progress("Sent email report to {$to}");
            } catch (\Throwable $e) {
                Log::error("Run #{$run->id}: sending the report by email failed: ".$e->getMessage(), $emailContext);
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
