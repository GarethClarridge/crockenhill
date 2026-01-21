<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Resources\PageResource;
use App\Models\Page;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use League\CommonMark\CommonMarkConverter;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Page $page */
        $page = $this->record;

        return [
            Actions\Action::make('view')
                ->label('View Page')
                ->icon('heroicon-o-eye')
                ->url(fn () => $page->route)
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Convert markdown to HTML body
        $converter = app(CommonMarkConverter::class);
        $data['body'] = (string) $converter->convert($data['markdown'] ?? '');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
