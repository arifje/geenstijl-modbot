<?php

namespace App\Filament\Resources\LogEntries;

use App\Filament\Resources\LogEntries\Pages\ListLogEntries;
use App\Models\LogEntry;
use BackedEnum;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Monolog\Level;
use UnitEnum;

class LogEntryResource extends Resource
{
    protected static ?string $model = LogEntry::class;

    protected static ?string $modelLabel = 'log entry';

    protected static ?string $pluralModelLabel = 'logs';

    protected static ?string $navigationLabel = 'Logs';

    protected static ?string $slug = 'logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    public const CATEGORIES = ['app' => 'Application', 'run' => 'Runs', 'queue' => 'Queue', 'scheduler' => 'Scheduler'];

    public static function levelColor(string $level): string
    {
        return match ($level) {
            'debug' => 'gray',
            'info', 'notice' => 'info',
            'warning' => 'warning',
            default => 'danger',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('created_at')->label('Time')->dateTime('d-m-Y H:i:s', 'Europe/Amsterdam'),
                TextEntry::make('level')->badge()->color(fn (string $state) => self::levelColor($state)),
                TextEntry::make('category')->formatStateUsing(fn (string $state) => self::CATEGORIES[$state] ?? $state),
                TextEntry::make('source')->columnSpanFull()->placeholder('—'),
                TextEntry::make('message')->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap']),
                TextEntry::make('context')
                    ->columnSpanFull()
                    ->visible(fn (LogEntry $record) => filled($record->context))
                    ->state(fn (LogEntry $record) => json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                    ->fontFamily('mono')
                    ->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 12px']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')->label('Time')->dateTime('d-m H:i:s', 'Europe/Amsterdam')->sortable(),
                TextColumn::make('level')->badge()->color(fn (string $state) => self::levelColor($state)),
                TextColumn::make('category')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => self::CATEGORIES[$state] ?? $state),
                TextColumn::make('message')->wrap()->limit(200)->searchable(),
                TextColumn::make('source')->toggleable(isToggledHiddenByDefault: true)->limit(40),
            ])
            ->filters([
                SelectFilter::make('min_level')
                    ->label('Level')
                    ->options([
                        Level::Debug->value => 'Debug and up',
                        Level::Info->value => 'Info and up',
                        Level::Warning->value => 'Warnings and errors',
                        Level::Error->value => 'Errors only',
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $value) => $q->where('level_value', '>=', (int) $value))),
                SelectFilter::make('category')->options(self::CATEGORIES)->multiple(),
                SelectFilter::make('period')
                    ->options(['1' => 'Last hour', '24' => 'Last 24 hours', '168' => 'Last 7 days'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $hours) => $q->where('created_at', '>=', now()->subHours((int) $hours)))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLogEntries::route('/'),
        ];
    }
}
