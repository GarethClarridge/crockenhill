<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Sermon\SermonSlugGenerator;
use App\Services\Import\ProductionCensusExporter;
use App\Support\CanonicalJson;
use RuntimeException;

/** Transport authority only; never selects, processes or approves a local cut. */
class ProductionSermonBinding
{
    public const HELD_IDS = [718, 720, 726];

    /**
     * Editorial fields a person enters on the admin sermon form. A bound replacement
     * never overwrites them: the production value stays, and a differing local value is
     * recorded in the replacement history instead. Scripture filter rows stay with them.
     */
    public const KEPT_FIELDS = [
        'title', 'date', 'service', 'content_type', 'slug', 'reference', 'scripture_passage_id', 'series',
        'show_summary', 'show_points', 'preacher', 'preacher_id', 'preacher_source',
        'preacher_confidence', 'needs_preacher_review',
    ];

    /** Derived text: production's value stays when non-empty, the local value fills an empty one. */
    public const FILL_IF_EMPTY_FIELDS = ['summary', 'meta_description', 'points'];

    /** Editorial fields a mapping entry may correct by hand, in place of the production value. */
    public const CORRECTABLE_FIELDS = ['title', 'service', 'series', 'summary', 'meta_description'];

    /** Main-publication payload keys that describe the kept editorial facts rather than the media. */
    private const MASKED_PUBLICATION_KEYS = [
        'title', 'reference', 'series', 'summary', 'meta_description', 'points', 'show_summary', 'show_points',
        'preacher_source', 'preacher_confidence', 'needs_preacher_review', 'preacher_display_name', 'preacher',
        'scripture_filters', 'scripture_passage', 'scripture_passage_outcome',
    ];

    /**
     * @param  array<string, mixed>  $bundle
     * @param  list<mixed>  $mapping
     * @return array<string, mixed>
     */
    public function bind(array $bundle, string $censusPath, array $mapping): array
    {
        $bundle = app(HistoricProcessingResultBundle::class)->validate($bundle);
        if (isset($bundle['production_binding'])) {
            throw new RuntimeException('A production binding already exists. Start with the original approved bundle.');
        }
        $census = $this->readCensus($censusPath);
        $entries = [];
        $runs = [];
        $targets = [];
        $allocated = [];
        foreach ($mapping as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['processing_key', 'target_sermon_id', 'corrections']) !== []
                || ! is_string($item['processing_key'] ?? null)
                || ! array_key_exists('target_sermon_id', $item)
                || ($item['target_sermon_id'] !== null && (! is_int($item['target_sermon_id']) || $item['target_sermon_id'] < 1))
                || isset($runs[$item['processing_key']])) {
                throw new RuntimeException('Invalid or doubly mapped production binding run.');
            }
            $item['corrections'] = $this->normaliseCorrections($item['corrections'] ?? [], $item['target_sermon_id']);
            $runs[$item['processing_key']] = $item;
            $target = $item['target_sermon_id'];
            $this->guardHeld($target);
            if ($target !== null && isset($targets[$target])) {
                throw new RuntimeException('Doubly mapped production sermon id.');
            }
            if ($target !== null) {
                $targets[$target] = true;
            }
        }
        $sourceHash = $bundle['bundle_hash'];
        foreach ($bundle['services'] as &$service) {
            $key = $service['media_graph']['processing_key'];
            if (! isset($runs[$key])) {
                throw new RuntimeException('Unmapped production binding run: '.$key);
            }
            $target = $runs[$key]['target_sermon_id'];
            $corrections = $runs[$key]['corrections'];
            unset($runs[$key]);
            $row = $target === null ? null : ($census['sermons'][$target] ?? null);
            if ($target !== null && ! is_array($row)) {
                throw new RuntimeException('Production binding target is absent from census.');
            }
            $main = $this->mainPublication($service);
            $allocation = null;
            if ($row === null) {
                foreach ($census['sermons'] as $candidate) {
                    if ($this->sameService($candidate, $service) && $candidate['content_type'] === $main['content_type']) {
                        throw new RuntimeException('Explicit create has an unmapped census sermon; enumerate its replacement or exclude the run.');
                    }
                }
                $others = array_column(array_filter(
                    $service['media_graph']['publications'],
                    static fn (array $item): bool => $item['slug'] !== $main['slug'],
                ), 'slug');
                $taken = array_flip(array_merge(array_column($census['sermons'], 'slug'), $allocated, $others));
                if (isset($taken[$main['slug']])) {
                    $occupant = array_search($main['slug'], array_column($census['sermons'], 'slug', 'id'), true);
                    $chosen = app(SermonSlugGenerator::class)->firstFree(
                        $main['slug'],
                        static fn (string $slug): bool => isset($taken[$slug]),
                    );
                    $allocation = [
                        'source_slug' => $main['slug'], 'allocated_slug' => $chosen,
                        'occupant_sermon_id' => $occupant === false ? null : $occupant,
                    ];
                    $this->retarget($service, $main['slug'], $chosen);
                }
                $allocated[] = $allocation['allocated_slug'] ?? $main['slug'];
            } else {
                if (! $this->sameService($row, $service, $corrections) || $row['content_type'] !== $main['content_type']) {
                    throw new RuntimeException('Production binding service/content identity differs from census.');
                }
                $this->validateOldAssets($row);
                $this->retarget($service, $main['slug'], $row['slug'], true);
            }
            $entries[] = [
                'processing_key' => $key, 'target_sermon_id' => $target,
                'corrections' => $corrections, 'slug_allocation' => $allocation,
                'graph_hash' => $service['media_graph']['logical_hash'],
                'previous_row' => $row, 'previous_row_hash' => CanonicalJson::hash($row),
            ];
        }
        unset($service);
        if ($runs !== []) {
            throw new RuntimeException('Production binding contains an unknown run.');
        }
        $binding = [
            'version' => 1, 'source_bundle_hash' => $sourceHash,
            'census_sha256' => $census['sha256'], 'census_header' => $census['header'],
            'held_sermon_ids' => self::HELD_IDS, 'entries' => $entries,
        ];
        $binding['binding_hash'] = CanonicalJson::hash($binding);
        $bundle['production_binding'] = $binding;
        $bundle['bundle_hash'] = CanonicalJson::hash(array_diff_key($bundle, ['bundle_hash' => true]));

        return app(HistoricProcessingResultBundle::class)->validate($bundle);
    }

    /** @param array<string, mixed> $bundle */
    public function validate(array $bundle): void
    {
        $binding = $bundle['production_binding'];
        if (! is_array($binding) || ($binding['version'] ?? null) !== 1
            || ($binding['held_sermon_ids'] ?? null) !== self::HELD_IDS
            || ! is_string($binding['binding_hash'] ?? null)
            || ! hash_equals(CanonicalJson::hash(array_diff_key($binding, ['binding_hash' => true])), $binding['binding_hash'])
            || ! is_string($binding['census_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $binding['census_sha256']) !== 1
            || ! is_array($binding['entries'] ?? null)
            || ! array_is_list($binding['entries'])
            || count($binding['entries']) !== count($bundle['services'])) {
            throw new RuntimeException('Invalid production binding hash or membership.');
        }
        $targets = [];
        foreach ($bundle['services'] as $index => $service) {
            $entry = $binding['entries'][$index] ?? null;
            if (! is_array($entry) || ! array_key_exists('target_sermon_id', $entry)
                || ($entry['processing_key'] ?? null) !== $service['media_graph']['processing_key']
                || ($entry['graph_hash'] ?? null) !== $service['media_graph']['logical_hash']
                || $entry['graph_hash'] !== app(HistoricProcessingResultInventory::class)->logicalHash($service['media_graph'])
                || ($entry['previous_row_hash'] ?? null) !== CanonicalJson::hash($entry['previous_row'] ?? null)) {
                throw new RuntimeException('Production binding run, graph or census row mismatch.');
            }
            $target = $entry['target_sermon_id'];
            $this->guardHeld($target);
            $corrections = $this->normaliseCorrections($entry['corrections'] ?? null, $target);
            if ($target === null) {
                if ($entry['previous_row'] !== null) {
                    throw new RuntimeException('Create binding carries a replacement row.');
                }
                $allocation = $entry['slug_allocation'] ?? null;
                $main = $this->mainPublication($service);
                if ($allocation !== null && (! is_array($allocation)
                    || ! is_string($allocation['source_slug'] ?? null) || ! is_string($allocation['allocated_slug'] ?? null)
                    || $allocation['source_slug'] === $allocation['allocated_slug']
                    || $allocation['allocated_slug'] !== $main['slug']
                    || ! str_starts_with($allocation['allocated_slug'], $allocation['source_slug'].'-'))) {
                    throw new RuntimeException('Invalid production binding slug allocation.');
                }

                continue;
            }
            $row = $entry['previous_row'];
            if (! is_int($target) || $target < 1 || isset($targets[$target]) || ! is_array($row)
                || ($row['id'] ?? null) !== $target || ! $this->sameService($row, $service, $corrections)
                || ($row['slug'] ?? null) !== $this->mainPublication($service)['slug']
                || ($row['content_type'] ?? null) !== $this->mainPublication($service)['content_type']) {
                throw new RuntimeException('Invalid, duplicate or mismatched production binding target.');
            }
            $targets[$target] = true;
            $this->validateOldAssets($row);
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>|null
     */
    public function forService(array $bundle, int $index): ?array
    {
        if (! isset($bundle['production_binding'])) {
            return null;
        }

        return [
            ...$bundle['production_binding']['entries'][$index],
            'binding_hash' => $bundle['production_binding']['binding_hash'],
            'census_sha256' => $bundle['production_binding']['census_sha256'],
        ];
    }

    /**
     * Rechecked under apply's transaction before any graph write. A replay must
     * still own the exact target and retained history, then passes live graph/asset verification.
     *
     * @param  array<string, mixed>|null  $entry
     */
    public function verifyTarget(?array $entry, bool $lock = false): ?Sermon
    {
        if ($entry === null || $entry['target_sermon_id'] === null) {
            return null;
        }
        $this->guardHeld($entry['target_sermon_id']);
        $query = Sermon::query()->whereKey($entry['target_sermon_id']);
        $target = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $target instanceof Sermon) {
            throw new RuntimeException('Production census target is absent.');
        }
        $history = $target->getAttribute('media_replacement_history') ?? [];
        $matching = array_values(array_filter($history, fn (array $item): bool => ($item['binding_hash'] ?? null) === $entry['binding_hash']));
        if ($matching !== []) {
            if (count($matching) !== 1 || ($matching[0]['processing_key'] ?? null) !== $entry['processing_key']
                || ($matching[0]['census_sha256'] ?? null) !== $entry['census_sha256']
                || CanonicalJson::hash($matching[0]['previous_row'] ?? null) !== $entry['previous_row_hash']
                || $target->livestream_processing_id !== $entry['processing_key']
                || $target->slug !== $entry['previous_row']['slug']
                || ! MediaProcessingLog::query()->where('processing_id', $entry['processing_key'])->where('sermon_id', $target->id)->exists()) {
                throw new RuntimeException('Production census replacement history or ownership differs.');
            }
            $row = $entry['previous_row'];
            $this->verifyOldBytes($row);

            return $target;
        }
        $observed = app(ProductionCensusExporter::class)->observeSermon($target->getRawOriginal());
        if (CanonicalJson::hash($observed) !== $entry['previous_row_hash']) {
            throw new RuntimeException('Production sermon or old media differs from the bound census.');
        }

        return $target;
    }

    /** @param array<string, mixed> $row */
    private function verifyOldBytes(array $row): void
    {
        $observed = app(ProductionCensusExporter::class)->observeSermon(array_diff_key($row, ['_assets' => true]));
        if (CanonicalJson::hash($observed['_assets'] ?? []) !== CanonicalJson::hash($row['_assets'] ?? [])) {
            throw new RuntimeException('Retained media differs from the bound census.');
        }
    }

    /** @param array<string, mixed> $row */
    private function validateOldAssets(array $row): void
    {
        foreach ($row['_assets'] ?? [] as $asset) {
            if (! is_array($asset) || ! in_array($asset['status'] ?? null, ['hashed', 'missing'], true)
                || ! is_string($asset['disk'] ?? null) || ! is_string($asset['path'] ?? null)
                || ($asset['status'] === 'hashed' && (! is_string($asset['sha256'] ?? null) || ! is_int($asset['bytes'] ?? null)))) {
                throw new RuntimeException('Production census has unreadable or unhashed old media.');
            }
        }
    }

    /**
     * Hand-ruled fixes only: an enumerated allow-list, string values, nothing automatic.
     * A corrected service must be the run's own service, so identity stays checkable.
     *
     * @return array<string, string>
     */
    private function normaliseCorrections(mixed $corrections, mixed $target): array
    {
        if ($corrections === null || $corrections === []) {
            return [];
        }
        if (! is_array($corrections) || $target === null || array_is_list($corrections)) {
            throw new RuntimeException('Invalid production binding corrections.');
        }
        foreach ($corrections as $field => $value) {
            if (! in_array($field, self::CORRECTABLE_FIELDS, true) || ! is_string($value) || trim($value) === ''
                || ($field === 'service' && SermonService::tryFrom($value) === null)) {
                throw new RuntimeException('Production binding correction is not allowed: '.$field);
            }
        }
        ksort($corrections);

        return $corrections;
    }

    /**
     * Point every publication that carried the run's main slug at the destination slug and
     * rehash the graph, so the reviewed graph is what will actually land.
     *
     * @param  array<string, mixed>  $service
     */
    private function retarget(array &$service, string $oldSlug, string $newSlug, bool $guardCollapse = false): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }
        foreach ($service['media_graph']['publications'] as &$publication) {
            if ($guardCollapse && $publication['slug'] === $newSlug) {
                throw new RuntimeException('Production binding slug would collapse different publications.');
            }
            if ($publication['slug'] === $oldSlug) {
                $publication['slug'] = $newSlug;
            }
        }
        unset($publication);
        $outcomes = data_get($service, 'media_graph.metadata.historic_import.scripture_passage_outcomes');
        if (is_array($outcomes) && array_key_exists($oldSlug, $outcomes)) {
            $outcomes[$newSlug] = $outcomes[$oldSlug];
            unset($outcomes[$oldSlug]);
            data_set($service, 'media_graph.metadata.historic_import.scripture_passage_outcomes', $outcomes);
        }
        $service['media_graph']['logical_hash'] = app(HistoricProcessingResultInventory::class)->logicalHash($service['media_graph']);
    }

    /**
     * Hash of a graph for comparison with a live one. A replaced production sermon keeps its
     * own editorial facts, so only the media graph around them is compared.
     *
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>|null  $entry
     */
    public function comparableHash(array $graph, ?array $entry): string
    {
        if (($entry['target_sermon_id'] ?? null) === null) {
            return $graph['logical_hash'];
        }

        $graph = $this->maskKeptEditorial($graph, $entry['previous_row']['slug']);

        return app(HistoricProcessingResultInventory::class)->logicalHash($graph);
    }

    /**
     * The reviewed graph with the kept editorial facts blanked on publications at the production
     * slug, so the live graph of a replaced sermon compares with the bundle's on media alone.
     *
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function maskKeptEditorial(array $graph, string $slug): array
    {
        foreach ($graph['publications'] ?? [] as $index => $publication) {
            if (($publication['slug'] ?? null) === $slug) {
                foreach (self::MASKED_PUBLICATION_KEYS as $key) {
                    $graph['publications'][$index][$key] = null;
                }
            }
        }

        return $graph;
    }

    private function guardHeld(mixed $target): void
    {
        if (in_array($target, self::HELD_IDS, true)) {
            throw new RuntimeException('Production sermon is explicitly held back: '.$target);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $service
     * @param  array<string, string>  $corrections
     */
    private function sameService(array $row, array $service, array $corrections = []): bool
    {
        return substr((string) ($row['date'] ?? ''), 0, 10) === $service['date']
            && ($corrections['service'] ?? $row['service'] ?? null) === $service['service'];
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function mainPublication(array $service): array
    {
        $main = array_values(array_filter($service['media_graph']['publications'] ?? [], fn (array $item): bool => ($item['section_key'] ?? null) === null));
        if (count($main) !== 1 || ($main[0]['date'] ?? null) !== $service['date'] || ($main[0]['service'] ?? null) !== $service['service']) {
            throw new RuntimeException('Production binding requires one main publication with matching service identity.');
        }
        $roles = array_merge([], ...array_column($service['assets'], 'roles'));
        foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
            if (is_string($main[0][$field] ?? null) && $main[0][$field] !== ''
                && ! in_array('publication:main:'.$field, $roles, true)) {
                throw new RuntimeException('Production binding main publication is missing a replacement asset role: '.$field);
            }
        }

        return $main[0];
    }

    /** @return array{sermons: array<int, array<string, mixed>>, sha256: string, header: array<string, mixed>} */
    private function readCensus(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read production census.');
        }
        $digest = hash_init('sha256');
        $whole = hash_init('sha256');
        $header = null;
        $footer = null;
        $counts = [];
        $sermons = [];
        try {
            while (($line = fgets($handle)) !== false) {
                hash_update($whole, $line);
                $item = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($item) || $footer !== null) {
                    throw new RuntimeException('Invalid production census structure.');
                }
                if ($header === null) {
                    if (($item['tag'] ?? null) !== 'header' || ($item['format'] ?? null) !== 'crockenhill_identity_census'
                        || ($item['version'] ?? null) !== 1 || ($item['media_hashes'] ?? null) !== true
                        || ! is_array($item['counts'] ?? null) || ! isset($item['schema']['sermons'])) {
                        throw new RuntimeException('Production census requires complete identity export with media hashes.');
                    }
                    $header = $item;
                    $counts = array_fill_keys(array_keys($header['counts']), 0);
                } elseif (($item['tag'] ?? null) === 'footer') {
                    $footer = $item;
                    if (($footer['counts'] ?? null) !== $counts || $header['counts'] !== $counts
                        || ($footer['rows'] ?? null) !== array_sum($counts)
                        || ($footer['sha256'] ?? null) !== hash_final($digest)) {
                        throw new RuntimeException('Production census digest or counts mismatch.');
                    }

                    continue;
                } elseif (($item['tag'] ?? null) === 'row' && isset($counts[$item['table'] ?? '']) && is_array($item['row'] ?? null)) {
                    $counts[$item['table']]++;
                    if ($item['table'] === 'sermons') {
                        $id = $item['row']['id'] ?? null;
                        if (! is_int($id) || $id < 1 || isset($sermons[$id])) {
                            throw new RuntimeException('Production census has invalid or duplicate sermon ids.');
                        }
                        $sermons[$id] = $item['row'];
                    }
                } else {
                    throw new RuntimeException('Invalid production census row.');
                }
                hash_update($digest, $line);
            }
            if (! feof($handle) || $header === null || $footer === null) {
                throw new RuntimeException('Incomplete production census.');
            }
        } finally {
            fclose($handle);
        }

        return ['sermons' => $sermons, 'sha256' => hash_final($whole), 'header' => $header];
    }
}
