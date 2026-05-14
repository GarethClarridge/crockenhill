<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\MediaType;
use App\Enums\SermonService;
use App\Models\Sermon;

class SermonRichnessDowngradeException extends ProcessingException
{
    public static function forExisting(Sermon $existing, SermonService $service, MediaType $incoming): self
    {
        return new self(sprintf(
            'Refusing to overwrite richer sermon. Existing sermon for %s %s is a %s; incoming is %s.',
            $existing->date->toDateString(),
            $service->value,
            self::describeExisting($existing),
            $incoming->value,
        ));
    }

    private static function describeExisting(Sermon $existing): string
    {
        if ($existing->livestream_processing_id !== null) {
            return 'livestream';
        }

        if (! empty($existing->video_file_path)) {
            return 'video';
        }

        return 'audio';
    }
}
