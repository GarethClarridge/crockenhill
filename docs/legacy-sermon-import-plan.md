# Legacy Sermon Import Plan

Import old tape recordings into the sermon database by matching MP3 filenames against the **Tape Index.csv** file.

---

## Two approaches

| | Plan A — API transcription | Plan B — Local Whisper (recommended) |
|---|---|---|
| **Transcription** | OpenAI `gpt-4o-transcribe` | Local Whisper model (free) |
| **AI analysis** | OpenAI `gpt-3.5-turbo` | OpenAI `gpt-3.5-turbo` |
| **Estimated cost** | ~$235–$550 | ~$5 |
| **Extra time** | None | ~15–30 hrs local processing |
| **Internet required** | Yes (during import) | Only for AI analysis step |
| **Code changes** | Import command only | Import command + pipeline builder |

Plan B runs transcription offline before the import, injecting the pre-generated transcripts so the pipeline skips its `TranscribeAudio` step entirely. The rest of the pipeline (AI analysis for summary/points, speaker identification, notifications) runs as normal.

---

## Plan A — API transcription

### When you're ready

1. Get the MP3 files accessible inside the Sail container (e.g. copy to `storage/app/legacy-imports/`)
2. Implement the Artisan command below
3. Run a dry run to check matches
4. Run the real import

---

## The Artisan command

Create `app/Console/Commands/ImportLegacySermonsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\ProcessingPipelineBuilder;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportLegacySermonsCommand extends Command
{
    protected $signature = 'sermons:import-legacy
                            {--dir= : Directory containing MP3 files to import}
                            {--csv= : Path to the Tape Index CSV file (defaults to storage/app/Tape Index.csv)}
                            {--dry-run : Preview imports without processing}
                            {--delay=0 : Seconds to wait between dispatches to avoid overloading the queue}
                            {--force : Re-import files that have already been imported}';

    protected $description = 'Import legacy sermon MP3 recordings, enriched with metadata from a Tape Index CSV';

    public function handle(ProcessingPipelineBuilder $pipelineBuilder): int
    {
        $dir = $this->option('dir');
        $dryRun = $this->option('dry-run');
        $delay = max(0, (int) $this->option('delay'));
        $force = $this->option('force');

        if (! $dir || ! is_dir($dir)) {
            $this->error('Please provide a valid directory path using --dir=');

            return Command::FAILURE;
        }

        $csvLookup = $this->loadCsvLookup($this->option('csv'));
        $this->info('Loaded '.count($csvLookup).' entries from CSV');

        $mp3Files = array_unique(array_merge(
            glob(rtrim($dir, '/') . '/*.mp3') ?: [],
            glob(rtrim($dir, '/') . '/*.MP3') ?: [],
        ));
        sort($mp3Files);

        if (empty($mp3Files)) {
            $this->warn('No MP3 files found in: '.$dir);

            return Command::SUCCESS;
        }

        $this->info('Found '.count($mp3Files).' MP3 file(s)');

        if ($dryRun) {
            $this->warn('DRY RUN MODE — no files will be imported');
        }

        $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
        $audioQueue = (string) config('media-processing.queues.audio', 'audio-processing');

        $imported = 0;
        $skipped = 0;
        $failed = 0;

        $progressBar = $this->output->createProgressBar(count($mp3Files));
        $progressBar->start();

        foreach ($mp3Files as $filePath) {
            $filename = basename($filePath);
            $tapeId = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
            $csvRow = $csvLookup[$tapeId] ?? null;

            if (! $force && MediaProcessingLog::where('original_filename', $filename)->exists()) {
                $this->newLine();
                $this->warn("Skipping {$filename} — already imported (use --force to re-import)");
                $skipped++;
                $progressBar->advance();

                continue;
            }

            if ($dryRun) {
                $match = $csvRow
                    ? "CSV match: \"{$csvRow['Title']}\" by {$csvRow['Preacher']} ({$csvRow['Date']})"
                    : 'no CSV match — will use filename/defaults';
                $this->newLine();
                $this->line("  {$filename} → {$match}");
                $progressBar->advance();

                continue;
            }

            try {
                $dateString = $csvRow['Date'] ?? null;
                $storageDir = 'sermons/' . ($dateString
                    ? date('Y/m', strtotime($dateString))
                    : now()->format('Y/m'));

                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $storedFilename = Str::uuid() . '.' . $extension;

                $storedPath = Storage::disk($sermonDisk)->putFileAs(
                    $storageDir,
                    new File($filePath),
                    $storedFilename
                );

                if (! $storedPath) {
                    throw new \RuntimeException("Failed to store file: {$filename}");
                }

                $processingMetadata = $this->buildProcessingMetadata($csvRow);
                $duration = $csvRow ? $this->parseDuration($csvRow['Duration'] ?? '') : null;

                $processingLog = MediaProcessingLog::create([
                    'processing_id' => Str::uuid()->toString(),
                    'processing_type' => 'audio',
                    'original_filename' => $filename,
                    'source_file_path' => $storedPath,
                    'file_size' => filesize($filePath) ?: null,
                    'duration' => $duration,
                    'status' => ProcessingStatus::PENDING,
                    'current_step' => 'audio_processing_initiated',
                    'processing_metadata' => $processingMetadata,
                ]);

                $jobs = $pipelineBuilder->buildAudioPipeline($processingLog);

                Bus::chain($jobs)
                    ->catch(function (\Throwable $e) use ($processingLog) {
                        $processingLog->update([
                            'status' => ProcessingStatus::FAILED,
                            'error_message' => 'Import processing failed: ' . $e->getMessage(),
                        ]);
                    })
                    ->onQueue($audioQueue)
                    ->dispatch();

                $imported++;

                if ($delay > 0) {
                    sleep($delay);
                }

            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to import {$filename}: {$e->getMessage()}");
                $failed++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info('Dry run complete. ' . count($mp3Files) . ' file(s) reviewed.');
        } else {
            $this->info("Import complete: {$imported} imported, {$skipped} skipped, {$failed} failed.");
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Load the Tape Index CSV into a lookup array keyed by Tape ID.
     *
     * @return array<string, array<string, string>>
     */
    private function loadCsvLookup(?string $csvPath): array
    {
        if (! $csvPath) {
            $csvPath = storage_path('app/Tape Index.csv');
        }

        if (! file_exists($csvPath)) {
            $this->warn("CSV file not found at: {$csvPath} — files will be imported without CSV metadata");

            return [];
        }

        $handle = fopen($csvPath, 'r');

        if (! $handle) {
            $this->warn("Could not open CSV file: {$csvPath}");

            return [];
        }

        $headers = null;
        $lookup = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = $row;

                continue;
            }

            if (count($row) !== count($headers)) {
                continue;
            }

            /** @var array<string, string> $data */
            $data = array_combine($headers, $row);

            // Strip any #filename# annotation from the Tape ID (e.g. "014A#14a.wav#" → "014A")
            $rawTapeId = $data['Tape ID'] ?? '';
            $cleanTapeId = strtoupper(explode('#', $rawTapeId)[0]);

            if ($cleanTapeId !== '') {
                $lookup[$cleanTapeId] = $data;
            }
        }

        fclose($handle);

        return $lookup;
    }

    /**
     * Build processing_metadata from a CSV row, mapped to the fields
     * the existing CreateSermonRecord job already understands.
     *
     * @param  array<string, string>|null  $csvRow
     * @return array<string, mixed>
     */
    private function buildProcessingMetadata(?array $csvRow): array
    {
        if (! $csvRow) {
            return [];
        }

        $id3Metadata = array_filter([
            'title'     => $csvRow['Title'] ?: null,
            'preacher'  => $csvRow['Preacher'] ?: null,
            'series'    => $csvRow['Series'] ?: null,
            'reference' => $this->buildReference($csvRow['Book'] ?? '', $csvRow['Reference'] ?? ''),
        ]);

        $metadata = ['import_source' => 'tape_index_csv'];

        if (! empty($id3Metadata)) {
            $metadata['id3_metadata'] = $id3Metadata;
        }

        $date = trim($csvRow['Date'] ?? '');

        if ($date) {
            $metadata['extracted_date'] = $date;
            $metadata['date_extraction_method'] = 'csv_import';
        }

        $ampm = strtoupper(trim($csvRow['AM/PM'] ?? ''));

        if ($ampm === 'AM' || $ampm === 'PM') {
            $metadata['extracted_service'] = $ampm === 'AM' ? 'morning' : 'evening';
            $metadata['service_extraction_method'] = 'csv_import';
        }

        return $metadata;
    }

    /**
     * Combine Book and Reference fields into a single Bible reference string.
     */
    private function buildReference(string $book, string $reference): ?string
    {
        $book = trim($book);
        $reference = trim($reference);

        if ($book && $reference) {
            return "{$book} {$reference}";
        }

        return $book ?: ($reference ?: null);
    }

    /**
     * Parse a HH:MM:SS duration string to total seconds.
     */
    private function parseDuration(string $duration): ?float
    {
        if (! preg_match('/^(\d+):(\d{2}):(\d{2})$/', trim($duration), $matches)) {
            return null;
        }

        return (float) (((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3]);
    }
}
```

---

## How metadata flows through the pipeline

The command maps CSV fields into `processing_metadata`, which the existing pipeline already reads:

| CSV column | Maps to | Used by |
|---|---|---|
| `Title` | `processing_metadata.id3_metadata.title` | `CreateSermonRecord` → `sermon.title` |
| `Preacher` | `processing_metadata.id3_metadata.preacher` | `CreateSermonRecord` → `sermon.preacher` + creates/matches `Preacher` model |
| `Series` | `processing_metadata.id3_metadata.series` | `CreateSermonRecord` → `sermon.series` |
| `Book` + `Reference` | `processing_metadata.id3_metadata.reference` | `CreateSermonRecord` → `sermon.reference` |
| `Date` | `processing_metadata.extracted_date` | `SermonCreationService::extractDate()` |
| `AM/PM` | `processing_metadata.extracted_service` | `SermonCreationService::extractServiceType()` |
| `Duration` | `media_processing_logs.duration` | `SermonCreationOptions` → `sermon.duration` |

Empty or missing CSV fields are simply omitted — the pipeline falls back to AI analysis and filename parsing for anything not supplied, exactly as with a normal upload.

### Tape ID quirk

One entry in the CSV has an annotated Tape ID: `014A#14a.wav#`. The `#...#` suffix is stripped automatically, so the lookup key is always the clean ID (e.g. `014A`). Name your file `014A.mp3` to match it.

---

## Usage

```bash
# Copy the CSV into the container's storage (or use the host path directly)
# The command defaults to looking for it at storage/app/Tape Index.csv

# Dry run — preview matches without importing anything
vendor/bin/sail artisan sermons:import-legacy \
    --dir=/path/to/mp3s \
    --dry-run

# Real import
vendor/bin/sail artisan sermons:import-legacy \
    --dir=/path/to/mp3s

# With a custom CSV path
vendor/bin/sail artisan sermons:import-legacy \
    --dir=/path/to/mp3s \
    --csv=/path/to/Tape\ Index.csv

# Throttle dispatches (recommended for large batches — gives the queue breathing room)
vendor/bin/sail artisan sermons:import-legacy \
    --dir=/path/to/mp3s \
    --delay=2

# Re-import files that were already imported
vendor/bin/sail artisan sermons:import-legacy \
    --dir=/path/to/mp3s \
    --force
```

---

## What happens after import

Each MP3 goes through the standard audio pipeline:

```
ValidateAudioFile → CreateSermonRecord → IdentifySpeaker → TranscribeAudio → ProcessTranscriptWithAI → SendCompletionNotification → CleanupTemporaryFiles
```

CSV-supplied fields (title, preacher, date, etc.) are set immediately when `CreateSermonRecord` runs. Transcription and AI analysis then fill in anything the CSV didn't have — summary, points, improved reference, etc.

Make sure the queue worker is running before importing a large batch:

```bash
vendor/bin/sail artisan queue:work --queue=audio-processing
```

---

## Tests to write (Plan A)

Create `tests/Feature/Console/ImportLegacySermonsCommandTest.php` covering:

- **CSV match** — processing log created with correct `id3_metadata`, `extracted_date`, `extracted_service`; pipeline dispatched
- **No CSV match** — file still imported with empty `processing_metadata`; pipeline dispatched
- **Dry run** — no processing logs created, output shows expected matches
- **Duplicate skip** — file with existing `original_filename` log is skipped; `--force` bypasses the skip
- **Missing `--dir`** — command exits with failure code
- **CSV not found** — command proceeds without metadata (warns user)
- **Empty CSV fields** — missing series/book/reference handled gracefully; only non-empty fields appear in `id3_metadata`

---

## Plan B — Local Whisper transcription

### Overview

Run transcription locally before the import. This produces `.txt` files alongside the MP3s. The import command then stores these transcripts on the server and dispatches a modified pipeline that skips the `TranscribeAudio` job, jumping straight to AI analysis.

```
Phase 1 (local): MP3s → faster-whisper → .txt files
Phase 2 (server): MP3s + .txt files → import command → pipeline (no TranscribeAudio)
```

### Phase 1: Local transcription script

Requires Python 3.9+ and `faster-whisper`:

```bash
pip install faster-whisper
```

Save as `transcribe_sermons.py` and run it locally alongside the MP3 folder:

```python
import os
import sys
from faster_whisper import WhisperModel

# Configuration
MP3_DIR = "/path/to/mp3s"
TRANSCRIPT_DIR = "/path/to/mp3s"  # saves .txt files next to the MP3s
MODEL_SIZE = "medium"              # or "large-v3" for better accuracy (slower)
DEVICE = "cpu"
COMPUTE_TYPE = "int8"              # use "float16" if you have a CUDA GPU

INITIAL_PROMPT = (
    "The following speech is a Christian sermon preached at Crockenhill Baptist Church, "
    "in the British conservative evangelical tradition."
)

print(f"Loading Whisper model: {MODEL_SIZE}")
model = WhisperModel(MODEL_SIZE, device=DEVICE, compute_type=COMPUTE_TYPE)

mp3_files = sorted([
    f for f in os.listdir(MP3_DIR)
    if f.lower().endswith(".mp3")
])

print(f"Found {len(mp3_files)} MP3 files")

for i, filename in enumerate(mp3_files, 1):
    base = os.path.splitext(filename)[0]
    transcript_path = os.path.join(TRANSCRIPT_DIR, f"{base}.txt")

    if os.path.exists(transcript_path):
        print(f"[{i}/{len(mp3_files)}] Skipping {filename} — already transcribed")
        continue

    mp3_path = os.path.join(MP3_DIR, filename)
    print(f"[{i}/{len(mp3_files)}] Transcribing {filename}...", end=" ", flush=True)

    try:
        segments, info = model.transcribe(
            mp3_path,
            language="en",
            initial_prompt=INITIAL_PROMPT,
            beam_size=5,
        )

        transcript = " ".join(segment.text.strip() for segment in segments)

        with open(transcript_path, "w", encoding="utf-8") as f:
            f.write(transcript)

        word_count = len(transcript.split())
        print(f"done ({word_count} words, {info.duration:.0f}s audio)")

    except Exception as e:
        print(f"FAILED: {e}", file=sys.stderr)
```

To run multiple workers in parallel across the directory, split the MP3s into folders (A–M and N–Z, for example) and run two terminal windows.

#### Speed estimates

| Hardware | Model | Approx. speed | 1,000 × 38 min sermons |
|---|---|---|---|
| MacBook M-series (CPU) | `medium` | ~15–20× real-time | ~32–42 hours |
| MacBook M-series (CPU) | `large-v3` | ~6–10× real-time | ~60–100 hours |
| NVIDIA GPU (CUDA) | `large-v3` | ~40–60× real-time | ~10–16 hours |

`medium` with two parallel workers is the practical sweet spot on a MacBook — roughly **15–20 hours** overnight.

#### Model quality trade-off

`medium` is accurate enough for clear sermon audio. `large-v3` adds ~10–15% fewer errors on names, Bible references, and proper nouns — worth it if time allows. Both are far better than the OpenAI Whisper-1 model.

---

### Phase 2: Import to production

Two small code changes are needed on top of the Plan A command.

#### Change 1 — Add a transcript-aware pipeline to `ProcessingPipelineBuilder`

In [app/Services/ProcessingPipelineBuilder.php](app/Services/ProcessingPipelineBuilder.php), add one method after `buildAudioPipeline()`:

```php
/**
 * Build job pipeline for audio processing when a transcript is pre-supplied.
 * Skips TranscribeAudio since the transcript file path is already set on the log.
 */
public function buildAudioPipelineWithTranscript(MediaProcessingLog $log): array
{
    return [
        new ValidateAudioFile($log),
        new CreateSermonRecord($log),
        new IdentifySpeaker($log),
        // TranscribeAudio is intentionally omitted — transcript pre-populated
        new ProcessTranscriptWithAI($log),
        new SendCompletionNotification($log),
        new CleanupTemporaryFiles($log),
    ];
}
```

#### Change 2 — Update `ImportLegacySermonsCommand`

Add `--transcripts-dir` to the signature and a `findTranscript()` helper. In the main loop, when a transcript file is found: store it on the `local` disk, set `transcript_file_path` on the processing log, and use the transcript-aware pipeline.

The diff relative to the Plan A command is:

```php
// Add to $signature:
{--transcripts-dir= : Directory containing pre-generated .txt transcript files}

// In handle(), resolve the transcripts directory:
$transcriptsDir = $this->option('transcripts-dir');

// In the per-file loop, after building $processingMetadata, add:
$transcriptContent = $this->findTranscript($transcriptsDir, $tapeId);
$transcriptPath = null;

if ($transcriptContent !== null) {
    $transcriptPath = 'transcripts/legacy_' . $processingLog->processing_id . '.txt';
    Storage::put($transcriptPath, $transcriptContent);
    $processingLog->update(['transcript_file_path' => $transcriptPath]);
}

// Then choose the pipeline:
$jobs = $transcriptPath
    ? $pipelineBuilder->buildAudioPipelineWithTranscript($processingLog)
    : $pipelineBuilder->buildAudioPipeline($processingLog);

// Add the helper method:
private function findTranscript(?string $transcriptsDir, string $tapeId): ?string
{
    if (! $transcriptsDir) {
        return null;
    }

    $path = rtrim($transcriptsDir, '/') . '/' . $tapeId . '.txt';

    if (! file_exists($path)) {
        return null;
    }

    $content = file_get_contents($path);

    return $content !== false && trim($content) !== '' ? $content : null;
}
```

> **Note on `transcript_file_path` and the sermon record:** `CreateSermonRecord` calls `SermonCreationOptions::fromAudioUpload()` which reads `$log->transcript_file_path` and sets it on the sermon. The `ProcessTranscriptWithAI` job then reads `$this->processingLog->transcript_file_path` via `Storage::get()` (default `local` disk). Both work correctly with `transcripts/legacy_{uuid}.txt` on the local disk.

---

### Usage (Plan B)

```bash
# Step 1: Run locally (takes 15–30 hours depending on hardware)
python transcribe_sermons.py

# Step 2: Copy MP3s and .txt transcripts to the server
# e.g. rsync -av /path/to/mp3s/ user@server:/app/storage/app/legacy-imports/

# Step 3 (on server): Dry run to check matches
vendor/bin/sail artisan sermons:import-legacy \
    --dir=storage/app/legacy-imports \
    --transcripts-dir=storage/app/legacy-imports \
    --dry-run

# Step 4: Real import
vendor/bin/sail artisan sermons:import-legacy \
    --dir=storage/app/legacy-imports \
    --transcripts-dir=storage/app/legacy-imports \
    --delay=2
```

Files with a matching `.txt` transcript use the no-transcription pipeline. Files without one fall back automatically to the standard pipeline (OpenAI transcription), so mixed batches are handled gracefully.

---

### Tests to write (Plan B additions)

Add to `ImportLegacySermonsCommandTest.php`:

- **Transcript found** — `transcript_file_path` set on log, `buildAudioPipelineWithTranscript` dispatched, `TranscribeAudio` not in the chain
- **Transcript missing** — falls back to standard pipeline with `TranscribeAudio`
- **Empty transcript file** — treated as missing, falls back to standard pipeline
- **`buildAudioPipelineWithTranscript`** unit test — asserts `TranscribeAudio` is absent and all other jobs present
