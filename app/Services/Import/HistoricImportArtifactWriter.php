<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\HistoricImportArtifactKind;
use App\Models\HistoricImportArtifact;
use App\Models\HistoricImportCheckpoint;
use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HistoricImportArtifactWriter
{
    public function __construct(
        private readonly HistoricImportArtifactRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function writeJson(
        HistoricImportOperation $operation,
        string $artifactKey,
        HistoricImportArtifactKind $kind,
        string $relativePath,
        array $payload,
        ?HistoricImportCheckpoint $checkpoint = null,
        bool $redact = true,
    ): HistoricImportArtifact {
        $safePayload = $redact ? $this->redactor->redact($payload) : $payload;

        return $this->write(
            operation: $operation,
            artifactKey: $artifactKey,
            kind: $kind,
            relativePath: $relativePath,
            contents: CanonicalJson::encode($safePayload).PHP_EOL,
            checkpoint: $checkpoint,
        );
    }

    public function write(
        HistoricImportOperation $operation,
        string $artifactKey,
        HistoricImportArtifactKind $kind,
        string $relativePath,
        string $contents,
        ?HistoricImportCheckpoint $checkpoint = null,
    ): HistoricImportArtifact {
        $this->assertCheckpointOwnership($operation, $checkpoint);
        $path = $this->path($operation, $relativePath);
        $ciphertext = Crypt::encryptString(base64_encode($contents));
        $temporary = $path.'.'.Str::uuid().'.tmp';
        $wroteArtifact = false;

        try {
            return DB::transaction(function () use (
                $operation,
                $checkpoint,
                $artifactKey,
                $kind,
                $relativePath,
                $path,
                $temporary,
                $ciphertext,
                &$wroteArtifact,
            ): HistoricImportArtifact {
                $this->writeAtomically($path, $temporary, $ciphertext);
                $wroteArtifact = true;

                return $operation->artifacts()->create([
                    'historic_import_checkpoint_id' => $checkpoint?->id,
                    'artifact_key' => $artifactKey,
                    'kind' => $kind,
                    'storage_disk' => 'local',
                    'relative_path' => 'historic-import/'.$operation->operation_id.'/'.$relativePath,
                    'sha256' => hash('sha256', $ciphertext),
                    'byte_size' => strlen($ciphertext),
                    'encrypted' => true,
                ]);
            });
        } catch (Throwable $throwable) {
            if ($wroteArtifact && is_file($path)) {
                @unlink($path);
            }

            throw $throwable;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function read(HistoricImportArtifact $artifact): string
    {
        if (! $artifact->encrypted || $artifact->storage_disk !== 'local') {
            throw new RuntimeException('Historic import artifact is not an encrypted local artifact.');
        }

        $operation = $artifact->operation;

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException('Historic import artifact has no owning operation.');
        }

        $relativePath = $this->operationRelativePath($artifact, $operation);
        $path = $this->path($operation, $relativePath, mustExist: true);
        $ciphertext = file_get_contents($path);

        if (! is_string($ciphertext)
            || strlen($ciphertext) !== $artifact->byte_size
            || ! hash_equals($artifact->sha256, hash('sha256', $ciphertext))) {
            throw new RuntimeException('Historic import artifact failed its ownership hash check.');
        }

        $decoded = base64_decode(Crypt::decryptString($ciphertext), true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Historic import artifact plaintext is invalid.');
        }

        return $decoded;
    }

    private function writeAtomically(string $path, string $temporary, string $contents): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Historic import artifacts never overwrite an existing target.');
        }

        $handle = fopen($temporary, 'x+b');

        if ($handle === false) {
            throw new RuntimeException('Historic import artifact temporary file could not be created.');
        }

        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('Historic import artifact permissions could not be restricted.');
            }

            $remaining = $contents;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('Historic import artifact write was incomplete.');
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Historic import artifact could not be flushed durably.');
            }
        } finally {
            fclose($handle);
        }

        if (! rename($temporary, $path)) {
            throw new RuntimeException('Historic import artifact could not be moved atomically into place.');
        }

        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Historic import artifact permissions are not 0600.');
        }
    }

    private function path(HistoricImportOperation $operation, string $relativePath, bool $mustExist = false): string
    {
        if ($relativePath === '' || str_starts_with($relativePath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Historic import artifact path must be relative.');
        }

        $segments = explode('/', str_replace('\\', '/', $relativePath));
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new RuntimeException('Historic import artifact path contains an unsafe segment.');
        }

        $root = storage_path('app/private/historic-import/'.$operation->operation_id);
        $this->ensurePrivateDirectory($root);
        $directory = $root;

        foreach (array_slice($segments, 0, -1) as $segment) {
            $directory .= DIRECTORY_SEPARATOR.$segment;
            $this->ensurePrivateDirectory($directory);
        }

        $path = $directory.DIRECTORY_SEPARATOR.end($segments);

        if ($mustExist && (! is_file($path) || is_link($path))) {
            throw new RuntimeException('Historic import artifact is missing or unsafe.');
        }

        return $path;
    }

    private function ensurePrivateDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Historic import artifact root contains a symlink.');
        }

        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Historic import artifact directory could not be created.');
        }

        if (! chmod($path, 0700)) {
            throw new RuntimeException('Historic import artifact directory permissions could not be restricted.');
        }

        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0700) {
            throw new RuntimeException('Historic import artifact directory permissions are not 0700.');
        }
    }

    private function assertCheckpointOwnership(
        HistoricImportOperation $operation,
        ?HistoricImportCheckpoint $checkpoint,
    ): void {
        if ($checkpoint !== null && $checkpoint->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException('Historic import artifact checkpoint belongs to another operation.');
        }
    }

    private function operationRelativePath(
        HistoricImportArtifact $artifact,
        HistoricImportOperation $operation,
    ): string {
        $prefix = 'historic-import/'.$operation->operation_id.'/';

        if (! str_starts_with($artifact->relative_path, $prefix)) {
            throw new RuntimeException('Historic import artifact path is not owned by its operation.');
        }

        return substr($artifact->relative_path, strlen($prefix));
    }
}
