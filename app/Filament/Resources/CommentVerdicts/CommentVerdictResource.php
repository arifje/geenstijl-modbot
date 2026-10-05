<?php

namespace App\Filament\Resources\CommentVerdicts;

use App\Enums\Category;
use App\Enums\Priority;
use App\Filament\Resources\CommentVerdicts\Pages\ListCommentVerdicts;
use App\Filament\Resources\ModerationRuns\ModerationRunResource;
use App\Models\CommentVerdict;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CommentVerdictResource extends Resource
{
    protected static ?string $model = CommentVerdict::class;

    protected static ?string $modelLabel = 'flag';

    protected static ?string $slug = 'flags';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return static::flagsTable($table);
    }

    public static function flagsTable(Table $table, bool $showRun = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('comment.article'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('comment.posted_at')->label('Posted')->dateTime('d-m H:i', 'Europe/Amsterdam')->sortable(),
                TextColumn::make('priority')->badge()
                    ->formatStateUsing(fn (Priority $state) => $state->label())
                    ->color(fn (Priority $state) => $state === Priority::High ? 'danger' : 'warning'),
                TextColumn::make('category')->formatStateUsing(fn (Category $state) => $state->label())->wrap(),
                TextColumn::make('comment.author_username')->label('Author')->searchable(),
                TextColumn::make('quote')->wrap()->searchable()
                    ->description(fn (CommentVerdict $record) => $record->reason),
                TextColumn::make('comment.article.title')->label('Article')->wrap()->limit(60)
                    ->url(fn (CommentVerdict $record) => $record->comment->article->url(), shouldOpenInNewTab: true),
                TextColumn::make('comment.status')->label('Status')->badge()
                    ->color(fn (string $state) => $state === 'visible' ? 'gray' : 'success'),
                TextColumn::make('moderation_run_id')->label('Run')->visible($showRun)
                    ->url(fn (CommentVerdict $record) => $record->moderation_run_id ? ModerationRunResource::getUrl('view', ['record' => $record->moderation_run_id]) : null),
            ])
            ->filters([
                SelectFilter::make('priority')->options(collect(Priority::cases())->mapWithKeys(fn (Priority $p) => [$p->value => $p->label()])),
                SelectFilter::make('category')->options(collect(Category::cases())->mapWithKeys(fn (Category $c) => [$c->value => $c->label()])),
                SelectFilter::make('status')->label('Comment status')
                    ->options(['visible' => 'Visible', 'removed' => 'Removed'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $status) => $q->whereHas('comment', fn ($c) => $c->where('status', $status)))),
                Filter::make('last_24h')->label('Last 24 hours')
                    ->query(fn (Builder $query) => $query->whereHas('comment', fn ($c) => $c->where('posted_at', '>=', now()->subDay()))),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Bewerk reactie')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (CommentVerdict $record) => $record->comment->url(), shouldOpenInNewTab: true),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommentVerdicts::route('/'),
        ];
    }
}
