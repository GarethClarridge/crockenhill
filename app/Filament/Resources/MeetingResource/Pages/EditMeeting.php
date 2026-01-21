<?php

namespace App\Filament\Resources\MeetingResource\Pages;

use App\Filament\Resources\MeetingResource;
use App\Models\Meeting;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use League\CommonMark\CommonMarkConverter;

class EditMeeting extends EditRecord
{
    protected static string $resource = MeetingResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Meeting $meeting */
        $meeting = $this->record;

        return [
            Actions\Action::make('view')
                ->label('View Meeting')
                ->icon('heroicon-o-eye')
                ->url(fn () => "/community/{$meeting->slug}")
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // If editing a page inline, convert markdown to body
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
