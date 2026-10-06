<?php

namespace App\Filament\Resources\Prompts\RelationManagers;

use App\Filament\Resources\Prompts\PromptResource;
use App\Models\CommentVerdict;
use App\Models\ModerationRun;
use App\Models\PromptVersion;
use App\Services\Analysis\PromptRepository;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $isModeration = $this->getOwnerRecord()->key === 'moderation';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('version')->fontFamily('mono'),
                IconColumn::make('active')->boolean()
                    ->state(fn (PromptVersion $record) => $record->id === $this->getOwnerRecord()->active_version_id),
                TextColumn::make('note')->wrap()->placeholder('—'),
                TextColumn::make('user.name')->label('By')->placeholder('—'),
                TextColumn::make('created_at')->label('Saved')->dateTime('d-m-Y H:i', 'Europe/Amsterdam'),
                TextColumn::make('runs')->label('Runs')->visible($isModeration)
                    ->state(fn (PromptVersion $record) => ModerationRun::where('prompt_version', $record->version)->count()),
                TextColumn::make('flags')->label('Flags')->visible($isModeration)
                    ->state(fn (PromptVersion $record) => CommentVerdict::where('prompt_version', $record->version)->count()),
            ])
            ->recordActions([
                Action::make('view')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (PromptVersion $record) => "Version {$record->version}")
                    ->modalSubmitAction(false)
                    ->schema([
                        TextEntry::make('content')->hiddenLabel()
                            ->state(fn (PromptVersion $record) => $record->content)
                            ->fontFamily('mono')
                            ->extraAttributes(['style' => 'white-space: pre-wrap']),
                    ])
                    ->modalWidth('5xl'),
                Action::make('activate')
                    ->label('Activate')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->hidden(fn (PromptVersion $record) => $record->id === $this->getOwnerRecord()->active_version_id)
                    ->requiresConfirmation()
                    ->modalDescription('The next run uses this version.')
                    ->action(function (PromptVersion $record) {
                        $this->getOwnerRecord()->update(['active_version_id' => $record->id]);
                        app(PromptRepository::class)->forget();
                        Notification::make()->title("Version {$record->version} is active")->success()->send();
                        // Reload so the editor shows the activated content.
                        $this->redirect(PromptResource::getUrl('edit', ['record' => $this->getOwnerRecord()]));
                    }),
            ]);
    }
}
