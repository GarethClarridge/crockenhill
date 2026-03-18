<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;

final class ServiceSectionScenario
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    private ?MediaProcessingLog $processingLog = null;

    private ?ChurchServiceItem $churchServiceItem = null;

    private ?ServiceSectionType $type = null;

    public static function make(): self
    {
        return new self;
    }

    public function forProcessingLog(MediaProcessingLog $processingLog): self
    {
        $this->processingLog = $processingLog;

        return $this;
    }

    public function forChurchServiceItem(ChurchServiceItem $churchServiceItem): self
    {
        $this->churchServiceItem = $churchServiceItem;

        return $this;
    }

    public function type(ServiceSectionType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function needsManualReview(bool $needsManualReview = true): self
    {
        $this->attributes['needs_manual_review'] = $needsManualReview;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function state(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    public function create(): ServiceSection
    {
        $attributes = $this->attributes;

        if ($this->processingLog !== null) {
            $attributes['media_processing_log_id'] = $this->processingLog->id;
        }

        if ($this->churchServiceItem !== null) {
            $attributes['church_service_item_id'] = $this->churchServiceItem->id;
        }

        if ($this->type !== null) {
            $attributes['section_type'] = $this->type;
        }

        return ServiceSection::factory()->create($attributes);
    }
}
