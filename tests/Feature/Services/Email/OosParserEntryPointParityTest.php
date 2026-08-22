<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Email;

use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Enums\InboundEmailStatus;
use App\Jobs\ProcessInboundOosEmail;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosArchiveIdentityResolver;
use App\Services\Email\OosArchiveParseCacheBinding;
use App\Services\Email\OosCurationEntryFactory;
use App\Services\Email\OosCurationManifest;
use App\Services\Email\OosEmailParserService;
use App\Services\Email\OosSemanticParserCandidate;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixedOosSemanticParserCandidate;
use Tests\TestCase;

/**
 * The §8 contract test for the redesign plan's gate 9: the weekly and archive entry points compile
 * byte-identical canonical projections for the same source and parser fingerprint.
 *
 * Both entry points reach {@see OosEmailParserService::parse()}, so parity is
 * *structural* for anything inside that call. What is not structural, and is what this test
 * actually guards, is the three seams either side of it:
 *
 * - **what each path hands the parser.** The weekly path takes an `InboundEmail` as delivered;
 *   the archive path synthesises one from a curation entry. Two different constructions of the
 *   same source that disagree on subject, body or received date are two different model inputs,
 *   and the received date in particular feeds date resolution.
 * - **what each path stores.** The weekly path stores the parse itself. The archive path stores a
 *   raw extraction *and* a manifest-resolved projection, and only the raw half is the parser's own
 *   answer. Comparing the weekly parse against the archive's resolved projection would compare a
 *   parse against a curation decision and fail for a reason the contract does not care about.
 * - **the archive's cache round trip.** The archive may reuse a stored raw payload instead of
 *   reparsing. A lossy encode/decode would let a cached archive run diverge from a fresh weekly
 *   parse of the same bytes without any model call to blame it on.
 *
 * The archive's identity resolution is a deliberate, manifest-owned divergence rather than a
 * parity breach — {@see OosArchiveIdentityResolver} exists to overrule the
 * parser on identity and scope. It is asserted here as the *only* permitted difference, so that a
 * new divergence cannot hide behind it.
 */
#[Group('oos-parser-parity')]
class OosParserEntryPointParityTest extends TestCase
{
    use RefreshDatabase;

    private const ItemKey = '2026-07-12-am';

    /**
     * The date the manifest carries and the parse deliberately does not.
     *
     * {@see OosArchiveIdentityResolver} fills gaps in the parser's identity;
     * it does not overrule identity the parser already supplied. Read in code: it returns the parse
     * untouched once a plan has both a date and a service, and again when the plan's service is not
     * among the manifest's, so a manifest that simply *disagreed* with a confident parse would leave
     * the resolver a no-op. The fixture therefore has the extractor return a plan with no date, and
     * has the manifest supply one, which is the shape that actually exercises resolution.
     *
     * Established by mutation, not assumed: with a complete parse, reintroducing the pre-HIR2 defect
     * of caching the *resolved* result as the raw payload passed unnoticed here. With this gap it
     * fails, because the archive's raw payload would then carry a manifest date the weekly parse
     * has no way to know.
     */
    private const ManifestDate = '2026-07-12';

    /**
     * The one source document, declared once and given to both entry points independently.
     *
     * Stated rather than derived, and in particular the arrival date is written into the corpus
     * fixture's `source_date` frontmatter instead of being left to the curation factory's
     * "N days before the service" fallback. An earlier draft built the weekly email by copying the
     * archive email's own stored fields, which made the input comparison a tautology: shifting the
     * archive's received date by a day moved *both* sides and the test still passed. Both paths now
     * answer to these constants, so a change to either path's construction of the source shows up
     * as the divergence it is.
     */
    private const SourceSubject = 'Order of Service - 12th July';

    private const SourceReceivedDate = '2026-07-10';

    private const SourceBody = "Morning service\nAmazing Grace\nOpening prayer";

    /** @var list<string> */
    private array $temporaryDirectories = [];

    /** @var array<string, string> */
    private array $corpusArguments = [];

    /**
     * Fields that identify the run or the email rather than describing the parse.
     *
     * Two kinds, both excluded from the byte comparison for the same reason — they are properties
     * of *which* email was parsed, not of what the parser made of it, and they differ between any
     * two runs including two runs of the same entry point:
     *
     * - timings (`parsed_at`, latencies), which are wall-clock;
     * - `source_message_id` and `source_subject`, which name the email row itself. The weekly and
     *   archive paths necessarily hold two different `InboundEmail` records for one source
     *   document, so these cannot match and their matching would prove nothing.
     *
     * `source_message_id` is excluded from the comparison but asserted separately in
     * {@see self::both_entry_points_project_the_same_parse_for_the_same_source()}, so a projection
     * that stopped recording it altogether still fails rather than passing by omission.
     *
     * Stripped by key rather than by value, so a field that stops being identity-scoped fails
     * loudly here instead of being silently tolerated.
     *
     * @var list<string>
     */
    private const IdentityScopedProjectionKeys = [
        'parsed_at',
        'reparsed_at',
        'duration_ms',
        'latency_ms',
        'source_message_id',
    ];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach ((array) glob($directory.'/*/*') as $file) {
                unlink((string) $file);
            }

            foreach ((array) glob($directory.'/*') as $child) {
                is_dir((string) $child) ? rmdir((string) $child) : unlink((string) $child);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function both_entry_points_hand_the_parser_the_same_source_document(): void
    {
        $extractor = $this->bindRecordingExtractor();

        $archiveEmail = $this->runArchiveEntry();
        $archiveCall = $extractor->calls[0];

        $this->runWeeklyEntry();
        $weeklyCall = $extractor->calls[1];

        $this->assertSame(
            $archiveCall,
            $weeklyCall,
            'The archive and weekly entry points handed the extractor different subject, body or received date '
            .'for the same source, so any downstream parity is coincidental.',
        );
    }

    #[Test]
    public function both_entry_points_project_the_same_parse_for_the_same_source(): void
    {
        $this->bindRecordingExtractor();

        $archiveEmail = $this->runArchiveEntry();
        $archiveRaw = $this->archiveRawProjection($archiveEmail);

        $weeklyEmail = $this->runWeeklyEntry();
        $weeklyProjection = Arr::get($weeklyEmail->processing_metadata ?? [], 'parsing');

        $this->assertIsArray($weeklyProjection);

        /**
         * The one field the comparison below excludes, checked here instead, so that a projection
         * which stopped recording the source email altogether cannot pass by omission.
         */
        $this->assertSame($archiveEmail->message_id, Arr::get($archiveRaw, 'source_message_id'));
        $this->assertSame($weeklyEmail->message_id, Arr::get($weeklyProjection, 'source_message_id'));

        $this->assertSame(
            $this->canonicalise($archiveRaw),
            $this->canonicalise($weeklyProjection),
            'The weekly and archive entry points no longer compile byte-identical projections for the same '
            .'source. Gate 9 of the OoS parser redesign plan is the contract this breaks.',
        );
    }

    #[Test]
    public function the_archive_cache_round_trip_preserves_the_projection_byte_for_byte(): void
    {
        $this->bindRecordingExtractor();

        $archiveEmail = $this->runArchiveEntry();
        $stored = $this->archiveRawProjection($archiveEmail);

        $importService = app(InboundEmailImportService::class);
        $decoded = $importService->decodeParseResult($stored);

        $this->assertNotNull($decoded, 'A stored raw archive payload must decode back into a parse result.');
        $this->assertSame(
            $this->canonicalise($stored),
            $this->canonicalise($importService->encodeParseResult($decoded)),
            'Re-encoding a decoded archive cache payload changed it, so a cached archive run would diverge '
            .'from a fresh parse of the same bytes with no model call to account for the difference.',
        );
    }

    #[Test]
    public function archive_identity_resolution_is_the_only_permitted_divergence(): void
    {
        $this->bindRecordingExtractor();

        $archiveEmail = $this->runArchiveEntry();
        $raw = $this->archiveRawProjection($archiveEmail);
        $resolved = Arr::get($archiveEmail->processing_metadata ?? [], 'parsing');

        $this->assertIsArray($resolved);

        /**
         * Resolution must actually have done something, or the two assertions below hold
         * vacuously and this test degrades into a tautology without failing.
         */
        $this->assertNull(Arr::get($raw, 'resolved_date'), 'The fixture must reach the resolver with no parsed date.');
        $this->assertSame(self::ManifestDate, Arr::get($resolved, 'resolved_date'));

        $differing = array_keys(array_diff_assoc(
            array_map($this->canonicalise(...), $this->comparableProjection($resolved)),
            array_map($this->canonicalise(...), $this->comparableProjection($raw)),
        ));

        $this->assertNotContains(
            'items',
            $differing,
            'Identity resolution rewrote the parsed items. It is authorised to overrule identity and scope, '
            .'not to change what the parser read out of the source.',
        );
    }

    /**
     * Runs one archive entry through the real archive command and returns the `InboundEmail` it
     * synthesised.
     *
     * `--evaluate` rather than `--import` on purpose. Both modes reach the same `parseResult()`,
     * but only `--import` writes canonical services — and a canonical service on the parity date
     * is exactly what the *weekly* run's duplicate lookup then reacts to, dropping its confidence
     * and flipping its disposition to `review_required`. That reaction is correct behaviour and
     * the first draft of this test tripped over it: comparing an archive parse made against an
     * empty database with a weekly parse made against a database the archive run had just
     * populated measures the duplicate detector, not entry-point parity. Evaluating leaves both
     * runs facing the same canonical state.
     */
    private function runArchiveEntry(): InboundEmail
    {
        $corpus = $this->corpus();

        $this->artisan('oos:import-archive', [...$corpus, '--evaluate' => true])
            ->assertExitCode(0);

        return InboundEmail::query()
            ->where('message_id', OosCurationEntryFactory::messageId(self::ItemKey))
            ->firstOrFail();
    }

    /**
     * Runs the declared source document through the weekly job.
     *
     * Built from the class constants, not from the archive email, so the two entry points reach
     * the parser from genuinely independent constructions of the same source.
     */
    private function runWeeklyEntry(): InboundEmail
    {
        $weeklyEmail = InboundEmail::factory()->create([
            'subject' => self::SourceSubject,
            'body_plain' => self::SourceBody,
            'body_html' => null,
            'received_at' => self::SourceReceivedDate.' 09:00:00',
            'status' => InboundEmailStatus::Pending->value,
        ]);

        app()->call([new ProcessInboundOosEmail($weeklyEmail), 'handle']);

        return $weeklyEmail->refresh();
    }

    /** @return array<string, mixed> */
    private function archiveRawProjection(InboundEmail $archiveEmail): array
    {
        $raw = Arr::get(
            $archiveEmail->processing_metadata ?? [],
            OosArchiveParseCacheBinding::MetadataKey.'.raw_result',
        );

        $this->assertIsArray($raw, 'The archive run stored no raw extraction payload to compare against.');

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function comparableProjection(array $projection): array
    {
        return Arr::except($projection, self::IdentityScopedProjectionKeys);
    }

    /**
     * @throws JsonException
     */
    private function canonicalise(mixed $projection): string
    {
        return CanonicalJson::encode(
            is_array($projection) ? $this->stripIdentityScopedKeys($projection) : $projection,
        );
    }

    /**
     * @param  array<array-key, mixed>  $projection
     * @return array<array-key, mixed>
     */
    private function stripIdentityScopedKeys(array $projection): array
    {
        $stripped = [];

        foreach ($projection as $key => $value) {
            if (is_string($key) && in_array($key, self::IdentityScopedProjectionKeys, true)) {
                continue;
            }

            $stripped[$key] = is_array($value) ? $this->stripIdentityScopedKeys($value) : $value;
        }

        return $stripped;
    }

    /**
     * A fixed extractor that records what each entry point asked it.
     *
     * Fixed because the contract is about the two paths agreeing, and a model that answered
     * differently on two identical inputs would make a real divergence indistinguishable from
     * sampling noise. Recording because "same output" is only meaningful evidence of parity if the
     * inputs were the same too.
     */
    private function bindRecordingExtractor(): object
    {
        $recorder = new class
        {
            /** @var list<array{subject: ?string, body: string, received_date: ?string}> */
            public array $calls = [];
        };

        $candidate = FixedOosSemanticParserCandidate::using(
            function (OosEmailSourceDocument $source) use ($recorder): OosEmailItemExtractionResult {
                $recorder->calls[] = [
                    'subject' => $source->subject,
                    'body' => $source->promptBody(),
                    'received_date' => $source->receivedDate,
                ];

                return new OosEmailItemExtractionResult(
                    items: [
                        ['type' => 'song', 'title' => 'Amazing Grace'],
                        ['type' => 'prayer', 'title' => 'Opening prayer'],
                    ],
                    confidence: 0.95,
                    services: [[
                        'service' => 'morning',
                        // Deliberately dateless: the gap archive identity resolution fills.
                        'date' => null,
                        'items' => [
                            ['type' => 'song', 'title' => 'Amazing Grace'],
                            ['type' => 'prayer', 'title' => 'Opening prayer'],
                        ],
                        'confidence' => 0.95,
                    ]],
                );
            },
        );

        $this->app->bind(OosSemanticParserCandidate::class, fn () => $candidate);

        return $recorder;
    }

    /** @return array<string, string> */
    private function corpus(): array
    {
        $root = $this->temporaryDirectory();
        $verbatim = $root.'/verbatim';
        $formatted = $root.'/formatted';
        mkdir($verbatim, 0755, true);
        mkdir($formatted, 0755, true);

        $payload = "---\ntitle: \"Order for 2026-07-12\"\ndate: 2026-07-12\n"
            .'source_date: '.self::SourceReceivedDate."\n"
            .'source_subject: "'.self::SourceSubject."\"\n---\n\n".self::SourceBody."\n";

        file_put_contents($verbatim.'/'.self::ItemKey.'.md', $payload);

        $manifest = $root.'/manifest.json';
        file_put_contents($manifest, json_encode([
            'format' => 'crockenhill-oos-curation',
            'version' => 2,
            'batch_key' => 'oos-parity-batch',
            'entries' => [[
                'item_key' => self::ItemKey,
                'source_kind' => 'email',
                'verbatim_relative_path' => self::ItemKey.'.md',
                'verbatim_sha256' => hash_file('sha256', $verbatim.'/'.self::ItemKey.'.md'),
                'verbatim_byte_size' => filesize($verbatim.'/'.self::ItemKey.'.md'),
                'disposition' => 'include',
                'payload' => 'verbatim',
                'resolved_date' => self::ManifestDate,
                'resolved_service' => 'morning',
                'date_decision' => 'explicit',
                'content_scope' => 'full',
                'parse_decision' => 'strict',
                'decision_rule_version' => 'oos-curation-test-v1',
            ]],
        ], JSON_THROW_ON_ERROR));

        return $this->corpusArguments = [
            '--manifest' => $manifest,
            '--verbatim' => $verbatim,
            '--formatted' => $formatted,
        ];
    }

    private function planHash(): string
    {
        return app(OosCurationManifest::class)->plan(
            $this->corpusArguments['--verbatim'],
            $this->corpusArguments['--formatted'],
            $this->corpusArguments['--manifest'],
        )->planHash;
    }

    private function temporaryDirectory(): string
    {
        $directory = storage_path('app/testing/'.uniqid('oos-parity-', true));
        mkdir($directory, 0755, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
