<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CommentVerdicts\CommentVerdictResource;
use App\Models\CommentVerdict;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestFlags extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest flags';

    public function table(Table $table): Table
    {
        return CommentVerdictResource::flagsTable($table)
            ->query(CommentVerdict::query())
            ->filters([])
            ->defaultPaginationPageOption(10);
    }
}
