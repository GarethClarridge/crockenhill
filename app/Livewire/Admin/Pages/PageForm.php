<?php

namespace App\Livewire\Admin\Pages;

use App\Enums\PageArea;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;

trait PageForm
{
    public string $heading = '';

    public string $slug = '';

    public string $area = 'church';

    public bool $navigation = false;

    public string $description = '';

    public string $markdown = '';

    protected function rules(): array
    {
        $pageId = isset($this->page) && $this->page->exists ? $this->page->id : '';

        return [
            'heading' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:pages,slug,'.$pageId,
            'area' => ['required', 'string', 'in:'.implode(',', PageArea::values())],
            'navigation' => 'boolean',
            'description' => 'required|string|max:500',
            'markdown' => 'nullable|string',
        ];
    }

    public function updatedHeading(): void
    {
        if (empty($this->slug) || $this->slug === Str::slug($this->heading)) {
            $this->slug = Str::slug($this->heading);
        }
    }

    protected function convertMarkdown(): string
    {
        if (empty($this->markdown)) {
            return '';
        }

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $converter->convert($this->markdown)->getContent();
    }

    protected function getAreaOptions(): array
    {
        return collect(PageArea::cases())
            ->map(fn ($area) => ['id' => $area->value, 'name' => $area->label()])
            ->toArray();
    }
}
