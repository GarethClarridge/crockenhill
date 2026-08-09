<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SermonService;
use App\Models\ChurchService;
use Illuminate\Support\Carbon;

class SongUsageOccurrence
{
    public int $id;

    public ?int $church_service_id;

    public function __construct(
        public int $sourceId,
        public string $sourceType,
        public Carbon $date,
        public ?SermonService $service,
        public string $title,
        public ?ChurchService $churchService,
    ) {
        $this->id = $sourceId;
        $this->church_service_id = $churchService?->id;
    }
}
