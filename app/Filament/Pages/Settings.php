<?php

namespace App\Filament\Pages;

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
                    ->columns(2)
                    ->schema([
                        Toggle::make('slack_enabled')->label('Enabled')->inline(false),
                        TextInput::make('slack_channel')->label('Default channel')->required()
                            ->helperText('Schedules can override this. The bot must be a member of the channel.'),
                    ]),
                Section::make('Email (AhaSend)')
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
