<?php

namespace App\Filament\Pages;

use App\Services\Analysis\LanguageModelFactory;
use App\Services\Analysis\ModelCatalog;
use App\Services\Reporting\AhaSendMailer;
use App\Services\Reporting\SlackNotifier;
use App\Support\Settings as SettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Support\Facades\Artisan;
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
        $this->form->fill([
            ...SettingsStore::all(),
            // The current API keys: saved here, or from .env.
            ...collect(SettingsStore::SECRETS)->map(fn (string $path) => config($path))->all(),
        ]);
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
                    ->description('Each provider keeps its own model and API key, so switching back and forth is one click.')
                    ->columns(2)
                    ->schema([
                        Select::make('llm_provider')->label('Provider')->required()->live()
                            ->options(LanguageModelFactory::PROVIDERS),
                        Select::make('llm_effort')->label('Effort')->required()
                            ->options(['default' => 'Model default', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'])
                            ->helperText('How long the model thinks. Use "Model default" for models without reasoning/effort support.'),

                        $this->modelSelect('llm_model', 'anthropic', 'Claude model', 'ANTHROPIC_API_KEY'),
                        $this->keyField('anthropic_api_key', 'anthropic', 'ANTHROPIC_API_KEY'),
                        $this->modelSelect('openai_model', 'openai', 'OpenAI model', 'OPENAI_API_KEY'),
                        $this->keyField('openai_api_key', 'openai', 'OPENAI_API_KEY'),

                        TextInput::make('compatible_base_url')->label('Base URL')
                            ->visible(fn ($get) => $get('llm_provider') === 'openai_compatible')
                            ->required(fn ($get) => $get('llm_provider') === 'openai_compatible')
                            ->url()
                            ->live(onBlur: true)
                            ->placeholder('https://generativelanguage.googleapis.com/v1beta/openai')
                            ->helperText('Gemini: https://generativelanguage.googleapis.com/v1beta/openai · Mistral: https://api.mistral.ai/v1 · Groq: https://api.groq.com/openai/v1 · Ollama: http://<host>:11434/v1'),
                        $this->modelSelect('compatible_model', 'openai_compatible', 'Model', 'LLM_COMPATIBLE_API_KEY'),
                        $this->keyField('compatible_api_key', 'openai_compatible', 'LLM_COMPATIBLE_API_KEY'),

                        TextInput::make('llm_max_tokens')->label('Max output tokens')
                            ->numeric()->minValue(1000)->maxValue(128000)->required()
                            ->helperText('Max length of one answer, thinking included. When an answer hits it, ModBot splits the comments into smaller batches and tries again.'),
                        TextInput::make('llm_batch_size')->label('Comments per request')
                            ->numeric()->minValue(10)->maxValue(1000)->required()
                            ->helperText('Comments of one article sent in one request. Smaller batches give shorter answers and more requests.'),

                        Repeater::make('llm_price_overrides')
                            ->label('Model prices')
                            ->helperText(fn () => 'USD per million tokens, for the cost estimates. '
                                .count(config('llm_prices.models')).' Claude, OpenAI and Gemini models are built in (config/llm_prices.php). '
                                .'Add models that are missing, or override a built-in price, here.')
                            ->schema([
                                TextInput::make('model')->label('Model id')->required()->placeholder('e.g. gpt-5-mini'),
                                TextInput::make('input')->label('Input $ / M tokens')->numeric()->minValue(0)->required(),
                                TextInput::make('output')->label('Output $ / M tokens')->numeric()->minValue(0)->required(),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add model price')
                            ->reorderable(false)
                            ->columnSpanFull(),
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
                        TextInput::make('article_lookback_hours')->label('Default article window (hours)')->numeric()->minValue(1)->required()
                            ->helperText('Articles published in the last X hours, for the command line and as default for Run now. Schedules have their own. GeenStijl closes comments 12 hours after publication.'),
                    ]),
                Section::make('Login')
                    ->columns(2)
                    ->schema([
                        Toggle::make('password_login')->label('Password login')
                            ->helperText(fn () => 'On: password + a code by email. Off: only a code by email (no passwords to steal). '
                                .'Codes are sent with mail driver "'.config('mail.default').'"; send a test email first. '
                                .'If emails stop arriving, turn it back on with: php artisan modbot:password-login on')
                            ->columnSpanFull(),
                        TextInput::make('max_failed_logins')->label('Failed logins before lockout')->numeric()->minValue(3)->maxValue(100)->required()
                            ->helperText('Per email address. One IP address is locked after 4× as many failures. Wrong passwords, wrong codes and unknown addresses all count.'),
                        TextInput::make('lockout_minutes')->label('Lockout (minutes)')->numeric()->minValue(1)->maxValue(1440)->required()
                            ->helperText('How long a locked email address or IP address has to wait.'),
                    ]),
                Section::make('Report & data')
                    ->afterHeader([$this->cleanupAction()])
                    ->description('A cleanup runs every night at 04:00: old comments, runs, logs, failed jobs and expired cache entries are removed.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('report_max_items')->label('Max flags per report')->numeric()->minValue(1)->required(),
                        TextInput::make('retention_days')->label('Keep comments and runs (days)')->numeric()->minValue(7)->required(),
                        TextInput::make('log_retention_days')->label('Keep logs (days)')->numeric()->minValue(1)->required(),
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

    public function cleanupAction(): Action
    {
        return Action::make('cleanup')
            ->label('Run cleanup now')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalDescription(fn () => 'Removes comments and runs older than '.config('modbot.retention_days').' days, log entries older than '
                .config('modbot.log_retention_days').' days, old failed jobs and expired cache entries. Uses the saved settings.')
            ->action(function () {
                Artisan::call('modbot:prune');

                Notification::make()->title('Cleanup done')->body(preg_replace('/^INFO\s+/', '', trim(Artisan::output())))->success()->send();
            });
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

                $secret = SettingsStore::PROVIDER_SECRETS[$current['llm_provider']] ?? null;
                $keyBefore = $secret ? config(SettingsStore::SECRETS[$secret]) : null;

                foreach (array_intersect_key($current, SettingsStore::SECRETS) as $key => $value) {
                    if (filled($value)) {
                        config([SettingsStore::SECRETS[$key] => trim($value)]);
                    }
                }

                // Which key this test uses, masked; a typed key may not be saved yet.
                $keyUsed = $secret ? config(SettingsStore::SECRETS[$secret]) : null;
                $key = filled($keyUsed) && $keyUsed !== $keyBefore
                    ? SettingsStore::mask($keyUsed).' (typed here, not saved yet)'
                    : SettingsStore::keyHint($current['llm_provider']);

                $started = microtime(true);

                try {
                    $result = $factory->make($current['llm_provider'])->complete(
                        'Je test een verbinding. Antwoord met precies: OK',
                        'Test',
                    );
                } catch (\Throwable $e) {
                    SettingsStore::apply();
                    Notification::make()->title('AI test failed')->body($this->explain($e)."\nAPI key used: {$key}")->danger()->persistent()->send();

                    return;
                }

                SettingsStore::apply();

                $seconds = number_format(microtime(true) - $started, 1);

                Notification::make()
                    ->title('AI connection works')
                    ->body("{$result->model} answered \"".Str::limit($result->text, 40)."\" in {$seconds}s. API key used: {$key}")
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
            ->helperText(function ($get, ?string $state) use ($provider) {
                $error = $this->modelList($provider, $get('compatible_base_url'), $state)['error'];

                return trim(($error ? "Model list unavailable: {$error}" : 'Models are refreshed every 15 minutes.')
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

    /** Where to create an API key, per provider. */
    private const KEY_PAGES = [
        'anthropic' => 'https://platform.claude.com/settings/keys',
        'openai' => 'https://platform.openai.com/api-keys',
        'generativelanguage.googleapis.com' => 'https://aistudio.google.com/apikey',
        'api.mistral.ai' => 'https://console.mistral.ai/api-keys',
        'api.groq.com' => 'https://console.groq.com/keys',
    ];

    /**
     * API key field, filled with the current key: the one saved here, or the
     * one from .env. A different key is stored encrypted; the .env key or an
     * empty field means: use .env.
     */
    private function keyField(string $field, string $provider, string $env): TextInput
    {
        return TextInput::make($field)
            ->label('API key')
            ->password()
            ->revealable()
            ->autocomplete('off')
            ->visible(fn ($get) => $get('llm_provider') === $provider)
            ->placeholder($provider === 'openai_compatible' ? 'Not needed for a local Ollama' : 'Paste an API key')
            ->helperText(fn () => $this->keySource($field, $env))
            ->hintAction(
                Action::make("keyPage_{$field}")
                    ->label('Get an API key')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn ($get) => self::keyPage($provider, $get('compatible_base_url')), shouldOpenInNewTab: true)
                    ->visible(fn ($get) => self::keyPage($provider, $get('compatible_base_url')) !== null),
            );
    }

    private function keySource(string $field, string $env): string
    {
        $envKey = config(SettingsStore::SECRETS[$field].'_env');

        if (SettingsStore::hasStoredSecret($field)) {
            return 'Saved in ModBot (encrypted).'.($envKey
                ? " Clear the field and save to use {$env} from .env again."
                : '');
        }

        return $envKey
            ? "From .env ({$env}). Change it and save to store your own key (encrypted) instead."
            : "Not set in .env ({$env}). Paste a key and save to store it (encrypted).";
    }

    private static function keyPage(string $provider, ?string $baseUrl): ?string
    {
        if ($provider !== 'openai_compatible') {
            return self::KEY_PAGES[$provider] ?? null;
        }

        return self::KEY_PAGES[parse_url((string) $baseUrl, PHP_URL_HOST) ?: ''] ?? null;
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

        $data['llm_price_overrides'] = array_values($data['llm_price_overrides'] ?? []);

        // API keys: a key other than the .env key is stored (encrypted); the
        // .env key itself, or an empty field, means "use .env".
        foreach (SettingsStore::SECRETS as $key => $path) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value === '' || $value === config("{$path}_env")) {
                SettingsStore::forgetSecret($key);
                $this->data[$key] = config($path);
            } else {
                SettingsStore::saveSecret($key, $value);
            }

            unset($data[$key]);
        }

        foreach (['article_lookback_hours', 'report_max_items', 'retention_days', 'log_retention_days', 'llm_max_tokens', 'llm_batch_size', 'max_failed_logins', 'lockout_minutes'] as $key) {
            $data[$key] = (int) $data[$key];
        }

        SettingsStore::save($data);

        Notification::make()->title('Settings saved')->success()->send();
    }
}
