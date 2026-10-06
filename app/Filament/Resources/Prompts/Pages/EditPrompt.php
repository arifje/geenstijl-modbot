<?php

namespace App\Filament\Resources\Prompts\Pages;

use App\Filament\Resources\Prompts\PromptResource;
use App\Models\Prompt;
use App\Services\Analysis\PromptRepository;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditPrompt extends EditRecord
{
    protected static string $resource = PromptResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            'content' => $this->record->activeVersion?->content ?? app(PromptRepository::class)->default($this->record->key),
            'note' => null,
        ];
    }

    /**
     * Never overwrite a version: publish the content as a new one.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Prompt $record */
        $record->publish($data['content'], $data['note'] ?? null, auth()->id());
        app(PromptRepository::class)->forget();

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Saved as version '.$this->record->fresh()->activeVersion->version;
    }

    protected function afterSave(): void
    {
        $this->fillForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('loadDefault')
                ->label('Load default')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Replaces the text in the editor with the default from resources/prompts. Nothing is saved until you click Save.')
                ->action(fn () => $this->data['content'] = app(PromptRepository::class)->default($this->record->key)),
        ];
    }
}
