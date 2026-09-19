<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricVideoRoundCloseout;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Deletion trigger: delete after Operation 4's completed round closeout and cover
 * have reached long-term custody and historic-import IC8 closes.
 */
class CompleteHistoricVideoRoundCloseoutCommand extends Command
{
    protected $signature = 'historic-import:complete-video-round-closeout
        {operation : Immutable historic import operation id}
        {cover : Signed historic-video round cover JSON}';

    protected $description = 'Atomically complete the legacy historic-video operation from its verified round cover';

    public function handle(HistoricVideoRoundCloseout $closeout): int
    {
        try {
            $operation = HistoricImportOperation::query()
                ->where('operation_id', (string) $this->argument('operation'))
                ->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('Historic video round closeout operation does not exist.');
            }

            $closeout->complete($operation, $this->read((string) $this->argument('cover')));
            $this->info('Historic video round closeout is complete.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Historic video round closeout cover is missing.');
        }

        try {
            $cover = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Historic video round closeout cover is invalid JSON.', previous: $exception);
        }

        if (! is_array($cover)) {
            throw new RuntimeException('Historic video round closeout cover must be an object.');
        }

        return $cover;
    }
}
