<?php

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Services\ModerationPipeline;
use App\Services\ModerationRunner;
use App\Services\Reporting\SlackFormatter;
use App\Services\RunOptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('modbot:run
    {--schedule= : Run with the settings of this schedule id}
    {--hours= : Comment window: comments posted in the last X hours (default MODBOT_WINDOW_HOURS)}
    {--article-hours= : Article window: articles published in the last X hours (default: the article window setting)}
    {--dry-run : Print the Slack report instead of sending it}
    {--no-slack : Do not post to Slack}
    {--no-email : Do not send the email report}
    {--channel= : Override the Slack channel}
    {--reanalyze : Analyse comments in the window again, replacing earlier verdicts}')]
#[Description('Sync recent comments, analyse new ones and send the moderation report')]
class RunModeration extends Command
{
    public function handle(ModerationRunner $runner, ModerationPipeline $pipeline, SlackFormatter $formatter): int
    {
        $options = $this->buildOptions();

        try {
            $run = $runner->run($options, fn (string $m) => $this->components->info($m));
        } catch (\Throwable $e) {
            report($e);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($options->dryRun) {
            $report = $pipeline->report($run);
            $this->line($formatter->main($report));
            foreach ($formatter->thread($report) as $message) {
                $this->newLine();
                $this->line('── thread ──');
                $this->line($message);
            }

            return self::SUCCESS;
        }

        // Partial runs and delivery errors fail the command, so they show up as
        // a failed scheduled task in the logs.
        if ($run->error || $run->status !== 'succeeded') {
            $this->components->error($run->error ?: "Run finished with status {$run->status}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function buildOptions(): RunOptions
    {
        $base = $this->option('schedule')
            ? RunOptions::fromSchedule(Schedule::findOrFail($this->option('schedule')))->toArray()
            : (new RunOptions(windowHours: config('modbot.window_hours')))->toArray();

        return RunOptions::fromArray(array_merge($base, array_filter([
            'windowHours' => $this->option('hours') ? (int) $this->option('hours') : null,
            'articleWindowHours' => $this->option('article-hours') ? (int) $this->option('article-hours') : null,
            'slackChannel' => $this->option('channel') ?: null,
            'dryRun' => $this->option('dry-run') ?: null,
            'reanalyze' => $this->option('reanalyze') ?: null,
        ], fn ($v) => $v !== null), array_filter([
            'sendSlack' => $this->option('no-slack') ? false : null,
            'sendEmail' => $this->option('no-email') ? false : null,
        ], fn ($v) => $v !== null)));
    }
}
