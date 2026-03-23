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

    public bool $admin = false;

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
            'admin' => 'boolean',
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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function pagePayload(array $validated): array
    {
        return [
            ...$validated,
            'admin' => $this->admin ? 'yes' : 'no',
            'body' => $this->convertMarkdown(),
        ];
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
