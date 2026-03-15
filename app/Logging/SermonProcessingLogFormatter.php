<?php

namespace App\Logging;

use Illuminate\Support\Number;
use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

class SermonProcessingLogFormatter
{
    /**
     * Customize the given logger instance.
     */
    public function __invoke(mixed $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new class extends LineFormatter
            {
                public function format(LogRecord $record): string
                {
                    // Custom format for sermon processing logs
                    $output = sprintf(
                        "[%s] %s.%s: %s\n",
                        $record->datetime->format('Y-m-d H:i:s'),
                        $record->level->name,
                        $record->channel,
                        $record->message
                    );

                    // Add context information in a structured way
                    if (! empty($record->context)) {
                        $context = $this->formatContext($record->context);
                        if ($context) {
                            $output .= 'Context: '.$context."\n";
                        }
                    }

                    // Add extra information if present
                    if (! empty($record->extra)) {
                        $extra = json_encode($record->extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        $output .= 'Extra: '.$extra."\n";
                    }

                    return $output;
                }

                /**
                 * @param  array<string, mixed>  $context
                 */
                private function formatContext(array $context): string
                {
                    // Format specific fields for better readability
                    $formatted = [];

                    foreach ($context as $key => $value) {
                        switch ($key) {
                            case 'processing_id':
                                $formatted[] = "ID: {$value}";
                                break;
                            case 'step':
                                $formatted[] = "Step: {$value}";
                                break;
                            case 'status':
                                $formatted[] = "Status: {$value}";
                                break;
                            case 'memory_usage':
                                $formatted[] = 'Memory: '.$this->formatBytes($value);
                                break;
                            case 'response_time_ms':
                                $formatted[] = "Response: {$value}ms";
                                break;
                            case 'file_size_human':
                                $formatted[] = "Size: {$value}";
                                break;
                            case 'timestamp':
                                // Skip timestamp as it's already in the log format
                                break;
                            default:
                                if (is_array($value) || is_object($value)) {
                                    $formatted[] = "{$key}: ".json_encode($value, JSON_UNESCAPED_SLASHES);
                                } else {
                                    $formatted[] = "{$key}: {$value}";
                                }
                        }
                    }

                    return implode(' | ', $formatted);
                }

                private function formatBytes(int $bytes): string
                {
                    return Number::fileSize($bytes, precision: 2);
                }
            });
        }
    }
}
