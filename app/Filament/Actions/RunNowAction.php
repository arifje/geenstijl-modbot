<?php

namespace App\Filament\Actions;

use App\Models\ModerationRun;
use App\Models\Schedule;
use App\Services\RunOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
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
            ->modalDescription('The run is queued and starts within a minute. Analysing new comments costs AI tokens.')
            ->fillForm(fn (?Schedule $record) => [
                'windowHours' => ($record ?? $schedule)?->window_hours ?? config('modbot.window_hours'),
                'articleWindowHours' => ($record ?? $schedule)?->article_window_hours ?? config('modbot.article_lookback_hours'),
                'sendSlack' => ($record ?? $schedule)?->send_slack ?? true,
                'sendEmail' => ($record ?? $schedule)?->send_email ?? false,
                'slackChannel' => ($record ?? $schedule)?->slack_channel ?: config('modbot.slack.channel'),
                'dryRun' => false,
                'reanalyze' => false,
            ])
            ->schema(self::schema())
            ->action(fn (array $data, ?Schedule $record) => self::queue($data, ($record ?? $schedule)?->id));
    }

    /**
     * Runs a failed, partial or cancelled run again as a new run, with the
     * same settings (which can be changed first).
     */
    public static function retry(): Action
    {
        return Action::make('retry')
            ->label('Retry')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (ModerationRun $record) => $record->canRetry())
            ->modalHeading(fn (ModerationRun $record) => "Retry run #{$record->id}")
            ->modalDescription('Queues a new run with the same settings, over the window up to now. Comments whose analysis failed are picked up again.')
            ->modalSubmitActionLabel('Retry')
            ->fillForm(fn (ModerationRun $record) => $record->retryForm())
            ->schema(self::schema())
            ->action(fn (array $data, ModerationRun $record) => self::queue($data, $record->schedule_id, $record->id));
    }

    /**
     * @return array<int, Component|Field>
     */
    private static function schema(): array
    {
        return [
            TextInput::make('articleWindowHours')->label('Article window (hours)')->numeric()->minValue(1)->maxValue(720)->required()
                ->helperText('Articles published in the last X hours.'),
            TextInput::make('windowHours')->label('Comment window (hours)')->numeric()->minValue(1)->maxValue(168)->required()
                ->helperText('Comments posted in the last X hours.'),
            Toggle::make('dryRun')->label('Dry run')->helperText('Analyse and store results, but send nothing. You can send the report from the run page later.')->live(),
            Toggle::make('sendSlack')->label('Post to Slack')->hidden(fn ($get) => $get('dryRun')),
            TextInput::make('slackChannel')->label('Slack channel')->hidden(fn ($get) => $get('dryRun') || ! $get('sendSlack')),
            Toggle::make('sendEmail')->label('Send email')->hidden(fn ($get) => $get('dryRun')),
            Toggle::make('reanalyze')->label('Re-analyse comments that were analysed before')->helperText('Replaces earlier flags in this window, e.g. after a prompt change.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function queue(array $data, ?int $scheduleId, ?int $retryOfId = null): void
    {
        $options = new RunOptions(
            windowHours: (int) $data['windowHours'],
            articleWindowHours: (int) $data['articleWindowHours'],
            sendSlack: (bool) ($data['sendSlack'] ?? false),
            sendEmail: (bool) ($data['sendEmail'] ?? false),
            slackChannel: $data['slackChannel'] ?? null,
            dryRun: (bool) $data['dryRun'],
            reanalyze: (bool) $data['reanalyze'],
            trigger: 'manual',
            scheduleId: $scheduleId,
            userId: auth()->id(),
            retryOfId: $retryOfId,
        );

        $run = ModerationRun::dispatch($options);

        Notification::make()
            ->title($retryOfId ? 'Retry queued' : 'Run queued')
            ->body("Run #{$run->id} starts within a minute.")
            ->success()
            ->send();
    }
}
