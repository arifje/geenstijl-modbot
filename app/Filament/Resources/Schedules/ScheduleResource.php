<?php

namespace App\Filament\Resources\Schedules;

use App\Filament\Actions\RunNowAction;
use App\Filament\Resources\Schedules\Pages\CreateSchedule;
use App\Filament\Resources\Schedules\Pages\EditSchedule;
use App\Filament\Resources\Schedules\Pages\ListSchedules;
use App\Models\Schedule;
use BackedEnum;
use Cron\CronExpression;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class ScheduleResource extends Resource
{
    protected static ?string $model = Schedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        Toggle::make('enabled')->default(true)->inline(false),
                        TextInput::make('cron')
                            ->label('Cron expression')
                            ->required()
                            ->default('0 8,20 * * *')
                            ->live(onBlur: true)
                            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                if (! CronExpression::isValidExpression((string) $value)) {
                                    $fail('This is not a valid cron expression.');
                                }
                            })
                            ->helperText(function (?string $state, $get) {
                                $examples = 'Examples: "0 8,20 * * *" (08:00 and 20:00), "0 */6 * * *" (every 6 hours), "30 7 * * 1-5" (weekdays 07:30).';

                                if (! $state || ! CronExpression::isValidExpression($state)) {
                                    return $examples;
                                }

                                $tz = $get('timezone') ?: 'Europe/Amsterdam';
                                $next = collect((new CronExpression($state))->getMultipleRunDates(3, now($tz)->toDateTime(), false, false, $tz))
                                    ->map(fn (\DateTime $d) => $d->format('D d-m H:i'))
                                    ->implode(', ');

                                return "Next: {$next}. {$examples}";
                            }),
                        Select::make('timezone')
                            ->options(collect(['Europe/Amsterdam', 'UTC'])->mapWithKeys(fn ($tz) => [$tz => $tz]))
                            ->default('Europe/Amsterdam')
                            ->required()
                            ->live(),
                        TextInput::make('article_window_hours')
                            ->label('Article window (hours)')
                            ->helperText('Only articles published in the last X hours. GeenStijl closes comments 12 hours after publication.')
                            ->numeric()->minValue(1)->maxValue(720)->default(24)->required(),
                        TextInput::make('window_hours')
                            ->label('Comment window (hours)')
                            ->helperText('Only comments posted in the last X hours. Usually the time between report runs.')
                            ->numeric()->minValue(1)->maxValue(168)->default(12)->required(),
                    ]),
                Section::make('Delivery')
                    ->columns(2)
                    ->schema([
                        Toggle::make('send_slack')->label('Post to Slack')->default(true)->live(),
                        TextInput::make('slack_channel')
                            ->label('Slack channel')
                            ->placeholder(fn () => config('modbot.slack.channel').' (default from settings)')
                            ->visible(fn ($get) => $get('send_slack')),
                        Toggle::make('send_email')->label('Send email')->default(true)
                            ->helperText(fn () => 'Recipients: '.(config('modbot.email.recipients') ?: 'none set')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('cron')->fontFamily('mono')->description(fn (Schedule $record) => $record->timezone),
                TextColumn::make('next_run')->label('Next run')
                    ->state(fn (Schedule $record) => $record->nextRunAt()?->timezone($record->timezone)->format('D d-m H:i') ?? '—'),
                TextColumn::make('window_hours')->label('Windows')
                    ->formatStateUsing(fn (Schedule $record) => "articles {$record->article_window_hours}h · comments {$record->window_hours}h"),
                IconColumn::make('send_slack')->label('Slack')->boolean(),
                IconColumn::make('send_email')->label('Email')->boolean(),
                TextColumn::make('last_run_at')->label('Last run')->since()->placeholder('never'),
                ToggleColumn::make('enabled'),
            ])
            ->recordActions([
                RunNowAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchedules::route('/'),
            'create' => CreateSchedule::route('/create'),
            'edit' => EditSchedule::route('/{record}/edit'),
        ];
    }
}
