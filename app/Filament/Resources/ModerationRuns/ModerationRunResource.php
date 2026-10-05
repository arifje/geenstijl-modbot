<?php

namespace App\Filament\Resources\ModerationRuns;

use App\Filament\Resources\ModerationRuns\Pages\ListModerationRuns;
use App\Filament\Resources\ModerationRuns\Pages\ViewModerationRun;
use App\Filament\Resources\ModerationRuns\RelationManagers\VerdictsRelationManager;
use App\Models\ModerationRun;
use App\Services\ModerationPipeline;
use App\Services\Reporting\SlackFormatter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class ModerationRunResource extends Resource
{
    protected static ?string $model = ModerationRun::class;

    protected static ?string $modelLabel = 'run';

    protected static ?string $slug = 'runs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 1;

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'succeeded' => 'success',
            'partial' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Run')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                        TextEntry::make('trigger')
                            ->formatStateUsing(fn (string $state, ModerationRun $record) => $record->schedule ? "{$state}: {$record->schedule->name}" : $state)
                            ->suffix(fn (ModerationRun $record) => $record->user ? " ({$record->user->name})" : ''),
                        TextEntry::make('dry_run')->label('Dry run')->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
                        TextEntry::make('started_at')->dateTime('d-m-Y H:i:s', 'Europe/Amsterdam'),
                        TextEntry::make('window_start')->label('Window')
                            ->formatStateUsing(fn (ModerationRun $record) => $record->window_start->timezone('Europe/Amsterdam')->format('d-m H:i').' – '.$record->window_end->timezone('Europe/Amsterdam')->format('d-m H:i')),
                        TextEntry::make('duration')->state(fn (ModerationRun $record) => $record->finished_at ? $record->started_at->diffForHumans($record->finished_at, true) : '—'),
                        TextEntry::make('model')->placeholder('—'),
                        TextEntry::make('prompt_version')->placeholder('—'),
                    ]),
                Section::make('Results')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('comments_count')->label('Comments in window'),
                        TextEntry::make('articles_count')->label('Articles'),
                        TextEntry::make('analyzed_count')->label('Analysed in this run'),
                        TextEntry::make('flagged_count')->label('Flagged in this run'),
                        TextEntry::make('failed_count')->label('Failed'),
                        TextEntry::make('tokens')->state(fn (ModerationRun $record) => number_format($record->input_tokens, 0, ',', '.').' in / '.number_format($record->output_tokens, 0, ',', '.').' out'),
                        TextEntry::make('cost')->state(fn (ModerationRun $record) => self::formatCost($record)),
                        TextEntry::make('delivery')->state(fn (ModerationRun $record) => collect([
                            $record->slack_ts ? "Slack {$record->slack_channel}" : null,
                            $record->email_message_id ? 'Email' : null,
                        ])->filter()->implode(', ') ?: 'Not sent'),
                        TextEntry::make('sentiment')->columnSpanFull()->placeholder('—'),
                        TextEntry::make('error')->columnSpanFull()->color('danger')->visible(fn (ModerationRun $record) => filled($record->error)),
                    ]),
                Section::make('Slack preview')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('slack_preview')
                            ->hiddenLabel()
                            ->state(fn (ModerationRun $record) => self::slackPreview($record))
                            ->fontFamily('mono')
                            ->extraAttributes(['style' => 'white-space: pre-wrap']),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('started_at')->dateTime('d-m-Y H:i', 'Europe/Amsterdam')->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('trigger')->badge()->color('gray')
                    ->description(fn (ModerationRun $record) => $record->schedule?->name),
                IconColumn::make('dry_run')->label('Dry')->boolean()->trueIcon(Heroicon::OutlinedEye)->falseIcon(null),
                TextColumn::make('window')->state(fn (ModerationRun $record) => round($record->window_start->diffInHours($record->window_end)).'h'),
                TextColumn::make('comments_count')->label('Comments')->numeric(),
                TextColumn::make('analyzed_count')->label('Analysed')->numeric(),
                TextColumn::make('flagged_count')->label('Flagged')->numeric()
                    ->color(fn (int $state) => $state > 0 ? 'warning' : null),
                TextColumn::make('cost')->state(fn (ModerationRun $record) => self::formatCost($record)),
                IconColumn::make('slack_ts')->label('Slack')->boolean()->state(fn (ModerationRun $record) => filled($record->slack_ts)),
                IconColumn::make('email_message_id')->label('Email')->boolean()->state(fn (ModerationRun $record) => filled($record->email_message_id)),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'running' => 'Running', 'succeeded' => 'Succeeded', 'partial' => 'Partial', 'failed' => 'Failed',
                ]),
                SelectFilter::make('trigger')->options(['schedule' => 'Schedule', 'manual' => 'Manual', 'cli' => 'CLI']),
                TernaryFilter::make('dry_run'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function formatCost(ModerationRun $record): string
    {
        $cost = $record->estimatedCost();

        return $cost === null ? '—' : '≈ $'.number_format($cost, 2);
    }

    public static function slackPreview(ModerationRun $record): string
    {
        if ($record->status === 'running' || $record->status === 'failed') {
            return '—';
        }

        $report = app(ModerationPipeline::class)->report($record);
        $formatter = app(SlackFormatter::class);

        return collect([$formatter->main($report), ...$formatter->thread($report)])->implode("\n\n──── thread ────\n\n");
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            VerdictsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModerationRuns::route('/'),
            'view' => ViewModerationRun::route('/{record}'),
        ];
    }
}
