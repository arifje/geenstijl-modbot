<?php

namespace App\Filament\Resources\Schedules;

use App\Filament\Actions\RunNowAction;
use App\Filament\Resources\Schedules\Pages\CreateSchedule;
use App\Filament\Resources\Schedules\Pages\EditSchedule;
use App\Filament\Resources\Schedules\Pages\ListSchedules;
use App\Models\Schedule;
use App\Support\ScheduleFrequency;
use BackedEnum;
use Cron\CronExpression;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
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
                Section::make('When')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        Toggle::make('enabled')->default(true)->inline(false),

                        Select::make('frequency')
                            ->label('How often?')
                            ->default('daily')
                            ->required()
                            ->live()
                            ->options([
                                'minutes' => 'Every few minutes',
                                'hours' => 'Every few hours',
                                'daily' => 'Every day at set times',
                                'weekly' => 'On certain days at set times',
                                'cron' => 'Advanced (cron expression)',
                            ])
                            ->columnSpanFull(),

                        Select::make('every_minutes')->label('Every')->default(15)
                            ->options(collect(ScheduleFrequency::MINUTES)->mapWithKeys(fn (int $m) => [$m => "{$m} minutes"]))
                            ->required()->live()
                            ->visible(fn ($get) => $get('frequency') === 'minutes'),

                        Select::make('every_hours')->label('Every')->default(1)
                            ->options(collect(ScheduleFrequency::HOURS)->mapWithKeys(fn (int $h) => [$h => $h === 1 ? 'hour' : "{$h} hours"]))
                            ->required()->live()
                            ->visible(fn ($get) => $get('frequency') === 'hours'),
                        TextInput::make('at_minute')->label('At minute')->default(0)
                            ->numeric()->minValue(0)->maxValue(59)->required()->live(onBlur: true)
                            ->helperText('0 = on the hour, 30 = half past.')
                            ->visible(fn ($get) => $get('frequency') === 'hours'),

                        CheckboxList::make('days')->label('Days')->default([1, 2, 3, 4, 5])
                            ->options(ScheduleFrequency::DAYS)
                            ->columns(7)->required()->live()
                            ->bulkToggleable()
                            ->visible(fn ($get) => $get('frequency') === 'weekly')
                            ->columnSpanFull(),
                        TagsInput::make('times')->label('At')->default(['08:00', '20:00'])
                            ->placeholder('Add a time, e.g. 08:00')
                            ->suggestions(collect(range(6, 23))->map(fn (int $h) => sprintf('%02d:00', $h))->all())
                            ->splitKeys([',', ' ', 'Tab'])
                            ->required()->live()
                            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                if ($error = ScheduleFrequency::timesError(array_values((array) $value))) {
                                    $fail($error);
                                }
                            })
                            ->helperText('Type a time and press Enter. All times need the same minutes, e.g. 08:00 and 20:00.')
                            ->visible(fn ($get) => in_array($get('frequency'), ['daily', 'weekly'], true))
                            ->columnSpanFull(),

                        TextInput::make('cron')->label('Cron expression')
                            ->required()->live(onBlur: true)
                            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                if (! CronExpression::isValidExpression((string) $value)) {
                                    $fail('This is not a valid cron expression.');
                                }
                            })
                            ->helperText('minute hour day-of-month month day-of-week, e.g. "0 8,20 * * *". See crontab.guru.')
                            ->visible(fn ($get) => $get('frequency') === 'cron')
                            ->columnSpanFull(),

                        TextEntry::make('preview')->label('Runs')
                            ->state(fn ($get) => self::preview($get))
                            ->columnSpanFull(),
                    ]),
                Section::make('What to analyse')
                    ->columns(2)
                    ->schema([
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
                Section::make('Advanced')
                    ->collapsed()
                    ->schema([
                        Select::make('timezone')
                            ->options(collect(['Europe/Amsterdam', 'UTC'])->mapWithKeys(fn ($tz) => [$tz => $tz]))
                            ->default('Europe/Amsterdam')
                            ->required()
                            ->live()
                            ->helperText('The times above are in this timezone.'),
                    ]),
            ]);
    }

    /**
     * Sorts by a value computed in PHP (from the cron expression). There are
     * only a few schedules, so the order is computed for all of them and
     * passed to the query as a CASE on the id.
     *
     * @param  \Closure(Schedule): array<int, mixed>  $key
     */
    private static function orderByComputed(Builder $query, string $direction, \Closure $key): Builder
    {
        $ordered = Schedule::all()->sortBy(fn (Schedule $s) => $key($s))->pluck('id')->values();

        if ($ordered->isEmpty()) {
            return $query;
        }

        $cases = $ordered->map(fn (int $id, int $position) => "WHEN {$id} THEN {$position}")->implode(' ');

        return $query->orderByRaw("CASE id {$cases} ELSE {$ordered->count()} END ".($direction === 'desc' ? 'desc' : 'asc'));
    }

    /**
     * Values for the fields that don't apply to a schedule's frequency (the
     * same as the field defaults), so switching "How often?" while editing
     * starts from sensible values.
     *
     * @return array<string, mixed>
     */
    public static function frequencyDefaults(): array
    {
        return ['frequency' => 'daily', 'every_minutes' => 15, 'every_hours' => 1, 'at_minute' => 0, 'times' => ['08:00', '20:00'], 'days' => [1, 2, 3, 4, 5]];
    }

    /**
     * Turns the frequency fields into the stored cron expression.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCron(array $data): array
    {
        $data['cron'] = ScheduleFrequency::toCron($data);

        if (! CronExpression::isValidExpression($data['cron'])) {
            throw ValidationException::withMessages(['data.frequency' => 'These settings do not form a valid schedule.']);
        }

        return Arr::except($data, ['frequency', 'every_minutes', 'every_hours', 'at_minute', 'times', 'days']);
    }

    /**
     * "Every day at 08:00 and 20:00 · next: Mon 06-10 20:00, Tue 07-10 08:00, ..."
     */
    private static function preview(callable $get): string
    {
        try {
            $cron = ScheduleFrequency::toCron([
                'frequency' => $get('frequency'),
                'every_minutes' => $get('every_minutes'),
                'every_hours' => $get('every_hours'),
                'at_minute' => $get('at_minute'),
                'times' => array_values((array) $get('times')),
                'days' => array_values((array) $get('days')),
                'cron' => $get('cron'),
            ]);
        } catch (\Throwable) {
            return '—';
        }

        if (in_array($get('frequency'), ['daily', 'weekly'], true) && ScheduleFrequency::timesError(array_values((array) $get('times')))) {
            return '—';
        }

        if (! CronExpression::isValidExpression($cron)) {
            return '—';
        }

        $tz = $get('timezone') ?: 'Europe/Amsterdam';
        $next = collect((new CronExpression($cron))->getMultipleRunDates(3, now($tz)->toDateTime(), false, false, $tz))
            ->map(fn (\DateTime $d) => $d->format('D d-m H:i'))
            ->implode(', ');

        return ScheduleFrequency::describe($cron)." · next: {$next}";
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('cron')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('cron')->label('When')
                    ->formatStateUsing(fn (string $state) => ScheduleFrequency::describe($state))
                    ->description(fn (Schedule $record) => $record->timezone === 'Europe/Amsterdam' ? null : $record->timezone)
                    // By time of day: 08:00 before 11:00, every 15 minutes first.
                    ->sortable(query: fn (Builder $query, string $direction) => self::orderByComputed(
                        $query, $direction, fn (Schedule $s) => [ScheduleFrequency::firstMinuteOfDay($s->cron), $s->name],
                    )),
                TextColumn::make('next_run')->label('Next run')
                    ->state(fn (Schedule $record) => $record->nextRunAt()?->timezone($record->timezone)->format('D d-m H:i') ?? '—')
                    // Disabled schedules (no next run) last.
                    ->sortable(query: fn (Builder $query, string $direction) => self::orderByComputed(
                        $query, $direction, fn (Schedule $s) => [$s->nextRunAt()?->getTimestamp() ?? PHP_INT_MAX, $s->name],
                    )),
                TextColumn::make('window_hours')->label('Windows')
                    ->formatStateUsing(fn (Schedule $record) => "articles {$record->article_window_hours}h · comments {$record->window_hours}h")
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderBy('article_window_hours', $direction)
                        ->orderBy('window_hours', $direction)),
                IconColumn::make('send_slack')->label('Slack')->boolean()->sortable(),
                IconColumn::make('send_email')->label('Email')->boolean()->sortable(),
                TextColumn::make('last_run_at')->label('Last run')->since()->placeholder('never')->sortable(),
                ToggleColumn::make('enabled')->sortable(),
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
