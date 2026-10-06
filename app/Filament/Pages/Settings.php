<?php

namespace App\Filament\Pages;

use App\Services\Reporting\AhaSendMailer;
use App\Services\Reporting\SlackNotifier;
use App\Support\Settings as SettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Settings stored in the database, overriding the environment defaults.
 * Secrets (API keys, tokens) stay in the environment.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(SettingsStore::all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Slack')
                    ->afterHeader([$this->testSlackAction()])
                    ->columns(2)
                    ->schema([
                        Toggle::make('slack_enabled')->label('Enabled')->inline(false),
                        TextInput::make('slack_channel')->label('Default channel')->required()
                            ->helperText('Schedules can override this. The bot must be a member of the channel.'),
                    ]),
                Section::make('Email (AhaSend)')
                    ->afterHeader([$this->testEmailAction()])
                    ->columns(2)
                    ->schema([
                        Toggle::make('email_enabled')->label('Enabled')->inline(false),
                        Textarea::make('email_recipients')->label('Recipients')
                            ->helperText('Comma separated; "Name <email>" is allowed.')
                            ->rows(2),
                    ]),
                Section::make('Analysis')
                    ->columns(2)
                    ->schema([
                        Select::make('llm_model')->label('Claude model')->required()
                            ->options([
                                'claude-opus-5-5' => 'Claude Opus 5.5 (default)',
                                'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (cheaper)',
                            ]),
                        Select::make('llm_effort')->label('Effort')->required()
                            ->options(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'])
                            ->helperText('Higher effort thinks longer: more accurate, slower and more expensive.'),
                        Toggle::make('only_approved')->label('Only approved comments')
                            ->helperText('Skip comments that are not approved yet (as in the Make scenario).'),
                        TextInput::make('article_lookback_hours')->label('Article lookback (hours)')->numeric()->minValue(1)->required()
                            ->helperText('Articles published this long ago are still checked for comments. GeenStijl closes comments after 12 hours.'),
                    ]),
                Section::make('Report & data')
                    ->columns(2)
                    ->schema([
                        TextInput::make('report_max_items')->label('Max flags per report')->numeric()->minValue(1)->required(),
                        TextInput::make('retention_days')->label('Keep data (days)')->numeric()->minValue(7)->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function testSlackAction(): Action
    {
        return Action::make('testSlack')
            ->label('Send test message')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->size('sm')
            ->fillForm(fn () => ['channel' => config('modbot.slack.channel')])
            ->schema([
                TextInput::make('channel')->label('Slack channel')->required()
                    ->helperText('Uses the bot token from the environment. The bot must be a member of the channel.'),
            ])
            ->modalSubmitActionLabel('Send')
            ->action(function (array $data, SlackNotifier $slack) {
                try {
                    $slack->notice('✅ Testbericht van ModBot: de Slack-koppeling werkt. (verstuurd door '.auth()->user()->name.')', $data['channel']);
                } catch (\Throwable $e) {
                    Notification::make()->title('Slack test failed')->body($this->explain($e))->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title("Test message posted to {$data['channel']}")->success()->send();
            });
    }

    public function testEmailAction(): Action
    {
        return Action::make('testEmail')
            ->label('Send test email')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->size('sm')
            ->fillForm(fn () => ['recipients' => auth()->user()->email])
            ->schema([
                TextInput::make('recipients')->label('Send to')->required()
                    ->helperText('Comma separated. Uses the AhaSend key and account from the environment.'),
            ])
            ->modalSubmitActionLabel('Send')
            ->action(function (array $data, AhaSendMailer $mailer) {
                try {
                    $id = $mailer->sendTest($data['recipients']);
                } catch (\Throwable $e) {
                    Notification::make()->title('Email test failed')->body($this->explain($e))->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Test email accepted by AhaSend')->body("Message id: {$id}")->success()->send();
            });
    }

    /**
     * Turn API errors into something actionable.
     */
    private function explain(\Throwable $e): string
    {
        $message = $e instanceof RequestException
            ? "HTTP {$e->response->status()}: ".($e->response->json('message') ?? $e->response->json('error') ?? $e->response->body())
            : $e->getMessage();

        $hint = match (true) {
            str_contains($message, 'not_in_channel') => ' Invite the bot to the channel first (/invite @modbot).',
            str_contains($message, 'channel_not_found') => ' Check the channel name, or invite the bot if it is a private channel.',
            str_contains($message, 'invalid_auth'), str_contains($message, 'not_authed') => ' The Slack bot token is invalid.',
            str_contains($message, 'HTTP 401'), str_contains($message, 'HTTP 403') => ' Check the API key and account id.',
            default => '',
        };

        return Str::limit($message, 300).$hint;
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (['article_lookback_hours', 'report_max_items', 'retention_days'] as $key) {
            $data[$key] = (int) $data[$key];
        }

        SettingsStore::save($data);

        Notification::make()->title('Settings saved')->success()->send();
    }
}
