<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use Illuminate\Support\Facades\Storage;

final class HistoricStagingActivation
{
    /**
     * @param  array<string, mixed>  $diskConfiguration
     * @param  array<string, mixed>  $storageConfiguration
     */
    public function __construct(
        private readonly string $stagingDisk,
        private readonly array $diskConfiguration,
        private readonly array $storageConfiguration,
    ) {}

    public function restore(): void
    {
        config(["filesystems.disks.{$this->stagingDisk}" => $this->diskConfiguration]);

        foreach ($this->storageConfiguration as $key => $value) {
            config([$key => $value]);
        }

        Storage::forgetDisk($this->stagingDisk);
    }
}
