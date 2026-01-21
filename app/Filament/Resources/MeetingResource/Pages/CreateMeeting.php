<?php

namespace App\Filament\Resources\MeetingResource\Pages;

use App\Filament\Resources\MeetingResource;
use Filament\Resources\Pages\CreateRecord;
use League\CommonMark\CommonMarkConverter;

class CreateMeeting extends CreateRecord
{
    protected static string $resource = MeetingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // If creating a page inline, convert markdown to body
        if (isset($data['page']['markdown'])) {
            $converter = app(CommonMarkConverter::class);
            $data['page']['body'] = (string) $converter->convert($data['page']['markdown'] ?? '');
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
