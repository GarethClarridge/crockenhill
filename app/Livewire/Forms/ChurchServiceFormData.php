<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ChurchServiceFormData extends Form
{
    public ?ChurchService $churchService = null;

    public string $date = '';

    public string $service = '';

    public function setChurchService(ChurchService $churchService): void
    {
        $this->churchService = $churchService;

        $this->fill([
            'date' => $churchService->date->format('Y-m-d'),
            'service' => $churchService->service->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $serviceId = $this->churchService?->id;

        return [
            'date' => [
                'required',
                'date',
                Rule::unique('church_services', 'date')
                    ->ignore($serviceId)
                    ->where(fn ($query) => $query->where('service', $this->service)),
            ],
            'service' => ['required', Rule::in(SermonService::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'date.unique' => 'A service for this date and service type already exists. Edit the existing service instead.',
        ];
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    public function serviceOptions(): array
    {
        return collect(SermonService::cases())
            ->map(fn (SermonService $service): array => [
                'id' => $service->value,
                'name' => $service->label(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    public function sectionTypeOptions(): array
    {
        return collect(ServiceSectionType::cases())
            ->map(fn (ServiceSectionType $type): array => [
                'id' => $type->value,
                'name' => $type->label(),
            ])
            ->all();
    }
}
