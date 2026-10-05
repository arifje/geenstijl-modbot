<?php

namespace App\Filament\Resources\ModerationRuns\RelationManagers;

use App\Filament\Resources\CommentVerdicts\CommentVerdictResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class VerdictsRelationManager extends RelationManager
{
    protected static string $relationship = 'verdicts';

    protected static ?string $title = 'Flags from this run';

    public function table(Table $table): Table
    {
        return CommentVerdictResource::flagsTable($table, showRun: false);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
