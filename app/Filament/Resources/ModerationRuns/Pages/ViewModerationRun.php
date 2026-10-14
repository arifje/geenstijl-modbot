<?php

namespace App\Filament\Resources\ModerationRuns\Pages;

use App\Filament\Actions\CancelRunAction;
use App\Filament\Actions\RunNowAction;
use App\Filament\Resources\ModerationRuns\ModerationRunResource;
use App\Models\ModerationRun;
use App\Services\ModerationRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewModerationRun extends ViewRecord
{
    protected static string $resource = ModerationRunResource::class;

    protected function getHeaderActions(): array
    {
        $deliverable = fn (ModerationRun $record) => in_array($record->status, ['succeeded', 'partial'], true);

        return [
            CancelRunAction::make()->after(fn () => $this->refreshFormData(['status', 'finished_at'])),
            RunNowAction::retry(),
            Action::make('sendSlack')
                ->label('Send to Slack')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible($deliverable)
                ->fillForm(['channel' => config('modbot.slack.channel')])
                ->schema([TextInput::make('channel')->label('Slack channel')->required()])
                ->modalDescription('Posts this run\'s report (main message + thread).')
                ->action(fn (ModerationRun $record, array $data) => $this->deliver($record, slack: true, email: false, channel: $data['channel'])),
            Action::make('sendEmail')
                ->label('Send email')
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible($deliverable)
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Sends this run\'s report to: '.config('modbot.email.recipients'))
                ->action(fn (ModerationRun $record) => $this->deliver($record, slack: false, email: true)),
        ];
    }

    private function deliver(ModerationRun $record, bool $slack, bool $email, ?string $channel = null): void
    {
        $errors = app(ModerationRunner::class)->deliver($record, $slack, $email, $channel);

        $errors === []
            ? Notification::make()->title('Report sent')->success()->send()
            : Notification::make()->title('Sending failed')->body(implode("\n", $errors))->danger()->send();

        $this->refreshFormData(['slack_ts', 'slack_channel', 'email_message_id', 'error']);
    }
}
