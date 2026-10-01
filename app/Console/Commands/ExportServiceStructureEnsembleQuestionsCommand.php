<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\EnsembleReviewExport;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Writes the operator's review page for a batch: every open ensemble question and every
 * majority decision, each with a clip of the recording and its transcript, plus the map the
 * apply command answers from. Reads only; nothing is answered or synced.
 */
class ExportServiceStructureEnsembleQuestionsCommand extends Command
{
    protected $signature = 'structure:ensemble-export-questions
        {runs?* : Processing log ids; without them, --since selects the batch}
        {--since= : Every run whose latest ensemble bundle started at or after this time}
        {--out= : Directory for index.html, clips/ and answers-map.json (default storage/scratch/ensemble-review-<time>)}
        {--title=Ensemble questions : Page title and heading}';

    protected $description = 'Write a review page of open ensemble questions and majority decisions, with a clip for each, read-only';

    public function handle(EnsembleReviewExport $export): int
    {
        $logs = $this->selectedRuns();

        if ($logs === []) {
            $this->error('No run with an ensemble bundle matches; pass run ids or --since.');

            return self::FAILURE;
        }

        $out = $this->outputDirectory();
        $clipDirectory = "{$out}/clips";

        if (! is_dir($clipDirectory) && ! mkdir($clipDirectory, 0755, true) && ! is_dir($clipDirectory)) {
            throw new RuntimeException("Cannot create {$clipDirectory}.");
        }

        $questions = [];
        $decided = [];
        $map = [];
        $failed = false;

        foreach ($logs as $log) {
            try {
                $items = $export->items($log);
                $audio = $items === [] ? null : $this->audioPath($log);
            } catch (Throwable $exception) {
                $this->error("Run {$log->id}: {$exception->getMessage()}");
                $failed = true;

                continue;
            }

            foreach ($items as $item) {
                $clips = [];

                foreach ($item['clips'] as $index => $clip) {
                    $file = "{$item['id']}-{$index}.mp3";
                    $this->cut((string) $audio, $clip['start'], $clip['end'], "{$clipDirectory}/{$file}");
                    $clips[] = ['src' => "clips/{$file}", 'offset' => $clip['start'], 'end' => $clip['end'], 'label' => $clip['label'], 'cues' => $clip['cues']];
                }

                $page = [
                    'id' => $item['id'],
                    'run' => $item['run'],
                    'decided' => $item['decided'],
                    'title' => $item['title'],
                    'context' => $item['context'],
                    'question' => $item['question'],
                    'options' => $item['options'],
                    'clips' => $clips,
                ];

                if ($item['decided']) {
                    $decided[] = $page;
                } else {
                    $questions[] = $page;
                }

                $map[$item['id']] = [
                    'run' => $item['run'],
                    'question_id' => $item['question_id'],
                    'decided' => $item['decided'],
                    'type' => $item['type'],
                    'title' => $item['title'],
                    'answers' => $item['answers'],
                ];
            }

            $this->line(sprintf('Run %d: %d open, %d settled by majority', $log->id, count(array_filter($items, static fn (array $item): bool => ! $item['decided'])), count(array_filter($items, static fn (array $item): bool => $item['decided']))));
        }

        file_put_contents("{$out}/answers-map.json", json_encode([
            'generated_at' => now()->toIso8601String(),
            'items' => $map,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $title = (string) $this->option('title');
        file_put_contents("{$out}/index.html", view('review-pages.ensemble-questions', [
            'title' => $title,
            'eyebrow' => 'Historic re-run · ensemble questions',
            'heading' => $title,
            'intro' => 'Each question is where the four drafts of a service disagreed. Listen, choose the version that matches the recording, and save. Below them, the disagreements three drafts settled: open one only where the majority is wrong.',
            'questions' => $questions,
            'decided' => $decided,
        ])->render());

        $this->info(sprintf('%d questions and %d majority decisions over %d runs written to %s', count($questions), count($decided), count($logs), $out));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<MediaProcessingLog> */
    private function selectedRuns(): array
    {
        $ids = array_map('intval', (array) $this->argument('runs'));
        $since = $this->option('since');

        if ($ids === [] && (! is_string($since) || $since === '')) {
            return [];
        }

        $query = MediaProcessingLog::query()->whereNotNull('processing_metadata->service_structure_ensemble')->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $threshold = is_string($since) && $since !== '' ? CarbonImmutable::parse($since) : null;

        return array_values($query->get()->filter(static function (MediaProcessingLog $log) use ($threshold): bool {
            if (! $threshold instanceof CarbonImmutable) {
                return true;
            }

            $bank = $log->processing_metadata?->raw['service_structure_ensemble'] ?? [];
            $startedAt = is_array($bank) && $bank !== [] ? (end($bank)['started_at'] ?? null) : null;

            return is_string($startedAt) && CarbonImmutable::parse($startedAt)->greaterThanOrEqualTo($threshold);
        })->all());
    }

    private function outputDirectory(): string
    {
        $out = $this->option('out');

        return is_string($out) && $out !== ''
            ? rtrim($out, '/')
            : storage_path('scratch/ensemble-review-'.now()->format('Ymd-His'));
    }

    /** The run's latest recorded service audio, as a local file ffmpeg can read. */
    private function audioPath(MediaProcessingLog $log): string
    {
        $audio = array_values(array_filter(
            ServiceArtifactStorage::recordedFor($log),
            static fn (array $entry): bool => $entry['kind'] === 'audio',
        ));

        if ($audio === []) {
            throw new RuntimeException('no service audio is recorded, so its questions cannot be heard.');
        }

        $latest = end($audio);
        $path = Storage::disk($latest['disk'])->path($latest['path']);

        if (! is_file($path)) {
            throw new RuntimeException("its service audio is missing at {$path}.");
        }

        return $path;
    }

    /** A mono 32 kbps MP3: iOS plays `.mp3` in `<audio>`, which a published `.mp4` it will not. */
    private function cut(string $source, float $from, float $to, string $destination): void
    {
        if (is_file($destination)) {
            return;
        }

        $result = Process::timeout(300)->run([
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-v', 'error', '-y',
            '-ss', (string) $from, '-to', (string) $to,
            '-i', $source,
            '-ac', '1', '-b:a', '32k',
            $destination,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException("Clip {$destination} failed: {$result->errorOutput()}");
        }
    }
}
