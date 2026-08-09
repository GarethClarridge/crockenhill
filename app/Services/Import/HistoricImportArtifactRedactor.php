<?php

declare(strict_types=1);

namespace App\Services\Import;

class HistoricImportArtifactRedactor
{
    private const SensitiveKeys = [
        'api_key',
        'authorization',
        'body_html',
        'body_plain',
        'password',
        'secret',
        'token',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        return $this->redactArray($payload);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redactArray(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SensitiveKeys, true)) {
                $payload[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redactArray($value);
            }
        }

        return $payload;
    }
}
