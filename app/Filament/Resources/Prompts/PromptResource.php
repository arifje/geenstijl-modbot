<?php

namespace App\Filament\Resources\Prompts;

use App\Filament\Resources\Prompts\Pages\EditPrompt;
use App\Filament\Resources\Prompts\Pages\ListPrompts;
use App\Filament\Resources\Prompts\RelationManagers\VersionsRelationManager;
use App\Models\Prompt;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PromptResource extends Resource
{
    protected static ?string $model = Prompt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->description(fn (?Prompt $record) => $record?->description)
                    ->schema([
                        Textarea::make('content')
                            ->label('Prompt')
                            ->required()
                            ->rows(32)
                            ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 13px; line-height: 1.5'])
                            ->helperText('Dutch. Comments are passed as data after this prompt. Saving creates a new version and uses it from the next run.'),
                        TextInput::make('note')
                            ->label('What changed?')
                            ->placeholder('e.g. stricter on personal attacks')
                            ->maxLength(255),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('activeVersion.user')->withCount('versions'))
            ->columns([
                TextColumn::make('name')->description(fn (Prompt $record) => $record->key),
                TextColumn::make('activeVersion.version')->label('Active version')->fontFamily('mono')
                    ->description(fn (Prompt $record) => $record->activeVersion?->note),
                TextColumn::make('activeVersion.created_at')->label('Since')->since()
                    ->description(fn (Prompt $record) => $record->activeVersion?->user?->name),
                TextColumn::make('versions_count')->label('Versions'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrompts::route('/'),
            'edit' => EditPrompt::route('/{record}/edit'),
        ];
    }
}
