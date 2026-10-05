<?php

namespace App\Filament\Resources\Comments;

use App\Filament\Resources\Comments\Pages\ListComments;
use App\Models\Comment;
use BackedEnum;
use Filament\Actions\Action;
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
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('author_username')->label('Author')
                    ->suffix(fn (Comment $record) => collect([$record->author_premium ? 'premium' : null, $record->author_newbie ? 'newbie' : null])->filter()->map(fn ($s) => " ({$s})")->implode('')),
                TextEntry::make('posted_at')->dateTime('d-m-Y H:i', 'Europe/Amsterdam'),
                TextEntry::make('status')->badge(),
                TextEntry::make('article.title')->label('Article')->columnSpanFull(),
                TextEntry::make('parent.content')->label('In reply to')->columnSpanFull()->visible(fn (Comment $record) => $record->parent !== null)
                    ->prefix(fn (Comment $record) => ($record->parent?->author_username ?? '?').': '),
                TextEntry::make('content')->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap']),
                Section::make('Flag')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (Comment $record) => $record->verdict !== null)
                    ->schema([
                        TextEntry::make('verdict.priority')->label('Priority')->formatStateUsing(fn ($state) => $state->label())->badge(),
                        TextEntry::make('verdict.category')->label('Category')->formatStateUsing(fn ($state) => $state->label()),
                        TextEntry::make('verdict.model')->label('Model'),
                        TextEntry::make('verdict.quote')->label('Quote')->columnSpanFull(),
                        TextEntry::make('verdict.reason')->label('Reason')->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('article', 'verdict'))
            ->defaultSort('posted_at', 'desc')
            ->columns([
                TextColumn::make('posted_at')->label('Posted')->dateTime('d-m H:i', 'Europe/Amsterdam')->sortable(),
                TextColumn::make('author_username')->label('Author')->searchable()->sortable(),
                TextColumn::make('content')->limit(120)->wrap()->searchable(),
                TextColumn::make('article.title')->label('Article')->limit(40)->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'visible' ? 'gray' : 'danger'),
                IconColumn::make('approved')->boolean(),
                IconColumn::make('analyzed_at')->label('Analysed')->boolean()->state(fn (Comment $record) => $record->analyzed_at !== null),
                TextColumn::make('verdict.priority')->label('Flag')->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => $state?->value === 'high' ? 'danger' : 'warning'),
                TextColumn::make('kudos')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reports')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('flagged')
                    ->queries(
                        true: fn (Builder $q) => $q->has('verdict'),
                        false: fn (Builder $q) => $q->doesntHave('verdict'),
                    ),
                TernaryFilter::make('analyzed')->nullable()->attribute('analyzed_at'),
                SelectFilter::make('status')->options(['visible' => 'Visible', 'removed' => 'Removed']),
                SelectFilter::make('article')->relationship('article', 'title')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('open')
                    ->label('Bewerk reactie')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Comment $record) => $record->url(), shouldOpenInNewTab: true),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComments::route('/'),
        ];
    }
}
