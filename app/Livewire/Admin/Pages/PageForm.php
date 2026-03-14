<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pages;

use App\Enums\PageArea;
use App\Services\SafeMarkdownRenderer;
use Illuminate\Support\Str;

trait PageForm
{
    public string $heading = '';

    public string $slug = '';

    public string $area = 'church';

    public bool $navigation = false;

    public string $description = '';

    public string $markdown = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $pageId = isset($this->page) && $this->page->exists ? $this->page->id : '';

        return [
            'heading' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                \Illuminate\Validation\Rule::unique('pages', 'slug')
                    ->where('area', $this->area)
                    ->ignore($pageId),
            ],
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
        return app(SafeMarkdownRenderer::class)->convert($this->markdown);
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    protected function getAreaOptions(): array
    {
        return collect(PageArea::cases())
            ->map(fn ($area) => ['id' => $area->value, 'name' => $area->label()])
            ->toArray();
    }
}
