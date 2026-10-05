<?php

namespace App\Filament\Resources\CommentVerdicts\Pages;

use App\Filament\Resources\CommentVerdicts\CommentVerdictResource;
use Filament\Resources\Pages\ListRecords;

class ListCommentVerdicts extends ListRecords
{
    protected static string $resource = CommentVerdictResource::class;
}
