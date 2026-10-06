<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Without password login the password is never used, but the column is required.
        $data['password'] ??= Str::random(64);

        return $data;
    }
}
