<?php

namespace App\Filament\Pages;

use App\Services\Analysis\LanguageModelFactory;
use App\Services\Analysis\ModelCatalog;
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

    protected static ?int $navigationSort = 3;

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
                Section::make('AI provider')
                    ->afterHeader([$this->testAiAction()])
                    ->description('API keys come from the environment. Each provider keeps its own model, so switching back and forth is one click.')
                    ->columns(2)
                    ->schema([
                        Select::make('llm_provider')->label('Provider')->required()->live()
                            ->options(LanguageModelFactory::PROVIDERS),
                        Select::make('llm_effort')->label('Effort')->required()
                            ->options(['default' => 'Model default', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'])
                            ->helperText('How long the model thinks. Use "Model default" for models without reasoning/effort support.'),

                        $this->modelSelect('llm_model', 'anthropic', 'Claude model', 'ANTHROPIC_API_KEY'),
                        $this->modelSelect('openai_model', 'openai', 'OpenAI model', 'OPENAI_API_KEY'),

                        TextInput::make('compatible_base_url')->label('Base URL')
                            ->visible(fn ($get) => $get('llm_provider') === 'openai_compatible')
                            ->required(fn ($get) => $get('llm_provider') === 'openai_compatible')
                            ->url()
                            ->live(onBlur: true)
                            ->placeholder('https://generativelanguage.googleapis.com/v1beta/openai')
                            ->helperText('Gemini: https://generativelanguage.googleapis.com/v1beta/openai · Mistral: https://api.mistral.ai/v1 · Groq: https://api.groq.com/openai/v1 · Ollama: http://<host>:11434/v1'),
                        $this->modelSelect('compatible_model', 'openai_compatible', 'Model', 'LLM_COMPATIBLE_API_KEY'),
                    ]),
                Section::make('Comments')
                    ->columns(2)
                    ->schema([
                        Select::make('approval_filter')->label('Which comments to analyse')->required()
                            ->options([
                                'unapproved' => 'Not yet approved (recommended)',
                                'approved' => 'Approved only (as in the Make scenario)',
                                'all' => 'All visible comments',
                            ])
                            ->helperText('Moderators approve in batches every few hours. With "Not yet approved", a comment is analysed while it waits for approval, '
                                .'and drops out of the report once a moderator approved or removed it. Run the schedule often (e.g. hourly) to catch comments before they are approved.'),
                        TextInput::make('article_lookback_hours')->label('Article lookback (hours)')->numeric()->minValue(1)->required()
                            ->helperText('Articles published this long ago are still checked for comments. GeenStijl closes comments after 12 hours.'),
                    ]),
                Section::make('Login')
                    ->schema([
                        Toggle::make('password_login')->label('Password login')
                            ->helperText(fn () => 'On: password + a code by email. Off: only a code by email (no passwords to steal). '
                                .'Codes are sent with mail driver "'.config('mail.default').'"; send a test email first. '
                                .'If emails stop arriving, turn it back on with: php artisan modbot:password-login on'),
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

    public function testAiAction(): Action
    {
        return Action::make('testAi')
            ->label('Test AI connection')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->size('sm')
            ->modalDescription('Sends a tiny request to the provider and model currently selected in this form (also when not saved yet). Costs a fraction of a cent.')
            ->modalSubmitActionLabel('Test')
            ->action(function (LanguageModelFactory $factory) {
                $current = $this->form->getRawState();

                // Test the values in the form, without saving them.
                foreach (array_intersect_key($current, SettingsStore::KEYS) as $key => $value) {
                    config([SettingsStore::KEYS[$key] => $value]);
                }

                $started = microtime(true);

                try {
                    $result = $factory->make($current['llm_provider'])->complete(
                        'Je test een verbinding. Antwoord met precies: OK',
                        'Test',
                    );
                } catch (\Throwable $e) {
                    Notification::make()->title('AI test failed')->body($this->explain($e))->danger()->persistent()->send();

                    return;
                } finally {
                    SettingsStore::apply();
                }

                $seconds = number_format(microtime(true) - $started, 1);

                Notification::make()
                    ->title('AI connection works')
                    ->body("{$result->model} answered \"".Str::limit($result->text, 40)."\" in {$seconds}s.")
                    ->success()
                    ->send();
            });
    }

    /** @var array<string, array{options: array<string, string>, error: ?string}> */
    private array $modelLists = [];

    /**
     * Model dropdown, filled from the provider's model list (cached 15 minutes).
     */
    private function modelSelect(string $field, string $provider, string $label, string $env): Select
    {
        return Select::make($field)
            ->label($label)
            ->visible(fn ($get) => $get('llm_provider') === $provider)
            ->required(fn ($get) => $get('llm_provider') === $provider)
            ->searchable()
            ->options(fn ($get, ?string $state) => $this->modelList($provider, $get('compatible_base_url'), $state)['options'])
            ->helperText(function ($get, ?string $state) use ($provider, $env) {
                $error = $this->modelList($provider, $get('compatible_base_url'), $state)['error'];

                return trim($this->keyStatus($provider, $env).' '.($error ? "Model list unavailable: {$error}" : 'Models are refreshed every 15 minutes.')
                    .($provider === 'openai_compatible' ? ' The model must support JSON schema output.' : ''));
            })
            ->suffixAction(
                Action::make("refresh_{$field}")
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->tooltip('Reload the model list')
                    ->action(function ($get) use ($provider) {
                        app(ModelCatalog::class)->forget($provider, ['base_url' => $get('compatible_base_url')]);
                        unset($this->modelLists[$provider]);
                    }),
            );
    }

    /**
     * @return array{options: array<string, string>, error: ?string}
     */
    private function modelList(string $provider, ?string $baseUrl, ?string $current): array
    {
        $this->modelLists[$provider] ??= (function () use ($provider, $baseUrl) {
            try {
                $overrides = $provider === 'openai_compatible' ? ['base_url' => $baseUrl] : [];

                return ['options' => app(ModelCatalog::class)->models($provider, $overrides), 'error' => null];
            } catch (\Throwable $e) {
                return ['options' => [], 'error' => Str::limit($e->getMessage(), 200)];
            }
        })();

        $list = $this->modelLists[$provider];

        // Keep the saved model selectable, also when it is not (or no longer) listed.
        if (filled($current) && ! array_key_exists($current, $list['options'])) {
            $list['options'] = [$current => $current] + $list['options'];
        }

        return $list;
    }

    private function keyStatus(string $provider, string $env): string
    {
        return app(LanguageModelFactory::class)->hasKey($provider)
            ? "API key ({$env}): set ✓."
            : "API key ({$env}): not set ✗.";
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
                    ->helperText('Comma separated. Sent the same way as the login codes.'),
            ])
            ->modalSubmitActionLabel('Send')
            ->action(function (array $data, AhaSendMailer $mailer) {
                try {
                    $mailerName = $mailer->sendTest($data['recipients']);
                } catch (\Throwable $e) {
                    Notification::make()->title('Email test failed')->body($this->explain($e))->danger()->persistent()->send();

                    return;
                }

                if (in_array($mailerName, ['log', 'array'], true)) {
                    Notification::make()
                        ->title('Email was not sent')
                        ->body("The mail driver is \"{$mailerName}\": emails, including login codes, are written to the log instead of sent. Set MAIL_MAILER=ahasend in .env.")
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->title('Test email sent')->body("Via mail driver \"{$mailerName}\", the same route as the login codes.")->success()->send();
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
