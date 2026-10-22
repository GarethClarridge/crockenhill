<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ServiceAuditPacket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes the post-round audit's per-service packets ({@see ServiceAuditPacket}) from an audit copy
 * of the database, never the working one: planner code can write back, and the audit reads a frozen
 * snapshot (audit plan §1). Each run gets `source.txt` (the timestamped source transcript, read first),
 * `delivered.json` (outputs, segment mapping, sections, bindings) and `packet.json` with their hashes.
 */
class BuildServiceAuditPacketsCommand extends Command
{
    protected $signature = 'service-audit:packets
        {runs* : Media processing log IDs}
        {--database= : The audit schema to read (must start with crockenhill_audit)}
        {--out= : Directory to write the packets into}';

    protected $description = 'Build read-only post-round audit packets from an audit copy of the database';

    public function handle(ServiceAuditPacket $packets): int
    {
        $database = (string) $this->option('database');
        $out = (string) $this->option('out');
        if (! str_starts_with($database, 'crockenhill_audit')) {
            $this->error('Name the audit schema with --database=crockenhill_audit…; the working and testing databases are refused.');

            return self::FAILURE;
        }
        if ($out === '') {
            $this->error('Name the output directory with --out.');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);
        if (DB::connection()->getDatabaseName() !== $database) {
            $this->error("Could not switch to {$database}.");

            return self::FAILURE;
        }

        $runIds = array_values(array_map(static fn (mixed $id): int => (int) $id, (array) $this->argument('runs')));
        $runs = MediaProcessingLog::query()->whereKey($runIds)->get()->keyBy('id');
        $unknown = array_values(array_filter($runIds, static fn (int $id): bool => ! $runs->has($id)));
        if ($unknown !== []) {
            $this->error('Unknown run(s) in '.$database.': '.implode(', ', $unknown).'. Nothing was written.');

            return self::FAILURE;
        }

        foreach ($runIds as $runId) {
            /** @var MediaProcessingLog $run */
            $run = $runs->get($runId);
            $packet = $packets->build($run);
            $dir = rtrim($out, '/').'/'.$run->id;
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $source = implode("\n", array_map(static fn (array $cue): string => sprintf('[%.2f–%.2f] %s', $cue['start'], $cue['end'], $cue['text']), $packet['source']['cues']))."\n";
            $delivered = json_encode($packet['delivered'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            file_put_contents("{$dir}/source.txt", $source);
            file_put_contents("{$dir}/delivered.json", $delivered);
            file_put_contents("{$dir}/packet.json", json_encode([
                'run' => $run->id, 'database' => $database, 'service' => $packet['source']['service'], 'duration' => $packet['source']['duration'],
                'source_transcript_sha256' => $packet['source']['transcript_sha256'], 'unobservable_windows' => $packet['source']['unobservable_windows'],
                'source_txt_sha256' => hash('sha256', $source), 'delivered_json_sha256' => hash('sha256', $delivered), 'gaps' => $packet['gaps'],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line(sprintf('run #%d: %d lines, %d outputs%s', $run->id, count($packet['source']['cues']), count($packet['delivered']['outputs']),
                $packet['gaps'] === [] ? '' : ' — gaps: '.implode('; ', $packet['gaps'])));
        }

        return self::SUCCESS;
    }
}
