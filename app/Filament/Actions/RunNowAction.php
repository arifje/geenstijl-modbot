<?php

namespace App\Filament\Actions;

use App\Jobs\RunModerationJob;
use App\Models\ModerationRun;
use App\Models\Schedule;
use App\Services\RunOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Queues a moderation run. The scheduler's queue worker picks it up
 * within a minute.
 */
class RunNowAction
{
    public static function make(?Schedule $schedule = null): Action
    {
        return Action::make('runNow')
            ->label('Run now')
            ->icon(Heroicon::OutlinedPlay)
            ->modalDescription('The run is queued and starts within a minute. Analysing new comments costs Claude tokens.')
            ->fillForm(fn (?Schedule $record) => [
                'windowHours' => ($record ?? $schedule)?->window_hours ?? config('modbot.window_hours'),
                'articleWindowHours' => ($record ?? $schedule)?->article_window_hours ?? config('modbot.article_lookback_hours'),
                'sendSlack' => ($record ?? $schedule)?->send_slack ?? true,
                'sendEmail' => ($record ?? $schedule)?->send_email ?? false,
                'slackChannel' => ($record ?? $schedule)?->slack_channel ?: config('modbot.slack.channel'),
                'dryRun' => false,
                'reanalyze' => false,
            ])
            ->schema([
                TextInput::make('articleWindowHours')->label('Article window (hours)')->numeric()->minValue(1)->maxValue(720)->required()
                    ->helperText('Articles published in the last X hours.'),
                TextInput::make('windowHours')->label('Comment window (hours)')->numeric()->minValue(1)->maxValue(168)->required()
                    ->helperText('Comments posted in the last X hours.'),
                Toggle::make('dryRun')->label('Dry run')->helperText('Analyse and store results, but send nothing. You can send the report from the run page later.')->live(),
                Toggle::make('sendSlack')->label('Post to Slack')->hidden(fn ($get) => $get('dryRun')),
                TextInput::make('slackChannel')->label('Slack channel')->hidden(fn ($get) => $get('dryRun') || ! $get('sendSlack')),
                Toggle::make('sendEmail')->label('Send email')->hidden(fn ($get) => $get('dryRun')),
                Toggle::make('reanalyze')->label('Re-analyse comments that were analysed before')->helperText('Replaces earlier flags in this window, e.g. after a prompt change.'),
            ])
            ->action(function (array $data, ?Schedule $record) use ($schedule) {
                $options = new RunOptions(
                    windowHours: (int) $data['windowHours'],
                    articleWindowHours: (int) $data['articleWindowHours'],
                    sendSlack: (bool) ($data['sendSlack'] ?? false),
                    sendEmail: (bool) ($data['sendEmail'] ?? false),
                    slackChannel: $data['slackChannel'] ?? null,
                    dryRun: (bool) $data['dryRun'],
                    reanalyze: (bool) $data['reanalyze'],
                    trigger: 'manual',
                    scheduleId: ($record ?? $schedule)?->id,
                    userId: auth()->id(),
                );

                $run = ModerationRun::queue($options);
                RunModerationJob::dispatch([...$options->toArray(), 'runId' => $run->id]);

                Notification::make()
                    ->title('Run queued')
                    ->body("Run #{$run->id} starts within a minute.")
                    ->success()
                    ->send();
            });
    }
}
