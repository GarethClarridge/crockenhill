<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\PageArea;
use App\Models\Page;
use App\Services\SafeMarkdownRenderer;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Form;

class PageFormData extends Form
{
    public ?Page $page = null;

    public string $heading = '';

    public string $slug = '';

    public string $area = 'church';

    public bool $admin = false;

    public bool $navigation = false;

    public string $description = '';

    public string $markdown = '';

    public function setPage(Page $page): void
    {
        $this->page = $page;

        $this->fill([
            'heading' => $page->heading,
            'slug' => $page->slug,
            'area' => $page->area->value,
            'admin' => $page->isAdminOnly(),
            'navigation' => $page->navigation,
            'description' => $page->description,
            'markdown' => $page->markdown ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $modelRules = Page::validationRules($this->page, $this->area);

        return [
            'heading' => $modelRules['heading'],
            'slug' => $modelRules['slug'],
            'area' => $modelRules['area'],
            'admin' => 'boolean',
            'navigation' => 'boolean',
            'description' => 'required|string|max:500',
            'markdown' => 'nullable|string',
            'sort_order' => $modelRules['sort_order'],
        ];
    }

    public function updatedHeading(string $value): void
    {
        if (empty($this->slug)) {
            $this->slug = Str::slug($value);
        }
    }

    public function store(): Page
    {
        $this->normalizeForSave();

        return Page::create($this->pagePayload($this->validate()));
    }

    public function update(): void
    {
        $this->normalizeForSave();

        $this->page?->update($this->pagePayload($this->validate()));
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function areaOptions(): array
    {
        return collect(PageArea::cases())
            ->map(fn (PageArea $area): array => ['id' => $area->value, 'name' => $area->label()])
            ->toArray();
    }

    protected function convertMarkdown(): string
    {
        return app(SafeMarkdownRenderer::class)->convert($this->markdown);
    }

    protected function normalizeForSave(): void
    {
        if ($this->slug === '') {
            $this->slug = Str::slug($this->heading);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function pagePayload(array $validated): array
    {
        $validated = Arr::except($validated, ['admin']);

        return [
            ...$validated,
            'admin' => $this->admin ? 'yes' : 'no',
            'body' => $this->convertMarkdown(),
        ];
    }
}
