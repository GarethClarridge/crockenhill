<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;

final class ProcessingLogScenario
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    private MediaType $type = MediaType::Audio;

    private ?ProcessingStatus $status = ProcessingStatus::PENDING;

    private ?User $owner = null;

    private ?ChurchService $churchService = null;

    private ?Sermon $sermon = null;

    public static function audio(): self
    {
        return new self;
    }

    public static function video(): self
    {
        return (new self)->as(MediaType::Video);
    }

    public static function livestream(): self
    {
        return (new self)->as(MediaType::Livestream);
    }

    public function as(MediaType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function pending(): self
    {
        $this->status = ProcessingStatus::PENDING;

        return $this;
    }

    public function processing(?string $step = null): self
    {
        $this->status = ProcessingStatus::PROCESSING;

        if ($step !== null) {
            $this->attributes['current_step'] = $step;
        }

        return $this;
    }

    public function completed(): self
    {
        $this->status = ProcessingStatus::COMPLETED;

        return $this;
    }

    public function failed(?string $message = null): self
    {
        $this->status = ProcessingStatus::FAILED;

        if ($message !== null) {
            $this->attributes['error_message'] = $message;
        }

        return $this;
    }

    public function withOwner(User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function forChurchService(ChurchService $churchService): self
    {
        $this->churchService = $churchService;

        return $this;
    }

    public function withSermon(Sermon $sermon): self
    {
        $this->sermon = $sermon;

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

    public function create(): MediaProcessingLog
    {
        $factory = MediaProcessingLog::factory();

        $factory = match ($this->type) {
            MediaType::Audio => $factory->audio(),
            MediaType::Video => $factory->video(),
            MediaType::Livestream => $factory->livestream(),
        };

        if ($this->status !== null) {
            $factory = match ($this->status) {
                ProcessingStatus::PENDING => $factory->pending(),
                ProcessingStatus::PROCESSING => $factory->processing(),
                ProcessingStatus::COMPLETED => $factory->completed(),
                ProcessingStatus::FAILED => $factory->failed(),
                ProcessingStatus::CANCELLED => $factory->cancelled(),
            };
        }

        $attributes = $this->attributes;

        if ($this->owner !== null) {
            $attributes['owner_user_id'] = $this->owner->id;
        }

        if ($this->churchService !== null) {
            $attributes['church_service_id'] = $this->churchService->id;
        }

        if ($this->sermon !== null) {
            $attributes['sermon_id'] = $this->sermon->id;
        }

        return $factory->create($attributes);
    }
}
