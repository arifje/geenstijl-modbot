<?php

namespace App\Console\Commands;

use App\Services\ModerationPipeline;
use App\Services\Reporting\AhaSendMailer;
use App\Services\Reporting\SlackFormatter;
use App\Services\Reporting\SlackNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('modbot:run
    {--hours= : Window in hours (default MODBOT_WINDOW_HOURS)}
    {--dry-run : Print the Slack report instead of sending it}
    {--no-slack : Do not post to Slack}
    {--no-email : Do not send the email report}
    {--channel= : Override the Slack channel}
    {--reanalyze : Analyse comments in the window again, replacing earlier verdicts}')]
#[Description('Sync recent comments, analyse new ones and send the moderation report')]
class RunModeration extends Command
{
    public function handle(
        ModerationPipeline $pipeline,
        SlackNotifier $slack,
        SlackFormatter $formatter,
        AhaSendMailer $mailer,
    ): int {
        $hours = (int) ($this->option('hours') ?: config('modbot.window_hours'));

        try {
            $run = $pipeline->run($hours, (bool) $this->option('reanalyze'), fn (string $m) => $this->components->info($m));
        } catch (\Throwable $e) {
            report($e);
            $this->components->error($e->getMessage());
            $this->notifyFailure($slack, $e);

            return self::FAILURE;
        }

        $report = $pipeline->report($run);

        if ($this->option('dry-run')) {
            $this->line($formatter->main($report));
            foreach ($formatter->thread($report) as $message) {
                $this->newLine();
                $this->line('── thread ──');
                $this->line($message);
            }

            return self::SUCCESS;
        }

        $failed = false;

        if (config('modbot.slack.enabled') && ! $this->option('no-slack')) {
            try {
                $channel = $this->option('channel') ?: config('modbot.slack.channel');
                $run->update(['slack_channel' => $channel, 'slack_ts' => $slack->send($report, $channel)]);
                $this->components->info("Posted report to Slack {$channel}");
            } catch (\Throwable $e) {
                report($e);
                $this->components->error('Slack: '.$e->getMessage());
                $failed = true;
            }
        }

        if (config('modbot.email.enabled') && ! $this->option('no-email')) {
            try {
                $run->update(['email_message_id' => $mailer->send($report)]);
                $this->components->info('Sent email report');
            } catch (\Throwable $e) {
                report($e);
                $this->components->error('Email: '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function notifyFailure(SlackNotifier $slack, \Throwable $e): void
    {
        if (! config('modbot.slack.enabled') || $this->option('no-slack') || $this->option('dry-run')) {
            return;
        }

        try {
            $slack->notice(
                '⚠️ ModBot-run mislukt: '.$e->getMessage(),
                $this->option('channel') ?: config('modbot.slack.channel'),
            );
        } catch (\Throwable $slackError) {
            report($slackError);
        }
    }
}
