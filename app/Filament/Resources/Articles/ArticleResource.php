<?php

namespace App\Filament\Resources\Articles;

use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Comments\CommentResource;
use App\Models\Article;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['comments as stored_comments_count', 'verdicts']))
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('published_at')->label('Published')->dateTime('d-m-Y H:i', 'Europe/Amsterdam')->sortable(),
                TextColumn::make('title')->searchable()->wrap()
                    ->url(fn (Article $record) => $record->url(), shouldOpenInNewTab: true),
                TextColumn::make('comments_count')->label('Comments')->numeric()->sortable()
                    ->description(fn (Article $record) => "{$record->stored_comments_count} stored"),
                TextColumn::make('verdicts_count')->label('Flags')->numeric()->sortable()
                    ->color(fn (int $state) => $state > 0 ? 'warning' : null),
                IconColumn::make('comments_closed')->label('Closed')->boolean(),
                TextColumn::make('comments_synced_at')->label('Last sync')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('comments')
                    ->label('Comments')
                    ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                    ->url(fn (Article $record) => CommentResource::getUrl('index', ['filters' => ['article' => ['value' => $record->id]]])),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
        ];
    }
}
