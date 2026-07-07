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

    public ?int $sortOrder = null;

    public string $lastGeneratedSlug = '';

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
            'sortOrder' => $page->sort_order,
        ]);

        $this->lastGeneratedSlug = (string) Str::slug($this->heading);
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
            'description' => $modelRules['description'],
            'markdown' => 'nullable|string|max:100000',
            'sortOrder' => $modelRules['sort_order'],
        ];
    }

    public function updatedHeading(string $value): void
    {
        $generatedSlug = (string) Str::slug($value);

        if ($this->slug === '' || $this->slug === $this->lastGeneratedSlug) {
            $this->slug = $generatedSlug;
        }

        $this->lastGeneratedSlug = $generatedSlug;
    }

    public function store(): Page
    {
        $this->normalizeForSave();

        return Page::query()->create($this->pagePayload($this->validate()));
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

        $validated = array_merge($validated, ['sort_order' => $validated['sortOrder'] ?? null]);
        unset($validated['sortOrder']);

        return [
            ...$validated,
            'admin' => $this->admin ? 'yes' : 'no',
            'body' => $this->convertMarkdown(),
        ];
    }
}
