<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\OosCandidateService;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\InboundEmailStatus;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Models\InboundEmail;
use App\Services\Email\OosArchiveParseCacheBinding;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecompileOosArchiveParseCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_to_overwrite_a_healthy_cache_with_a_worse_replay(): void
    {
        // The 2026-08-23 corpus sweep in miniature: this source's findings were cleared by a repair
        // call the replay deliberately omits, so it replays as a zero-item failure while the cache
        // it would overwrite is a clean two-item parse.
        $email = $this->archiveEmail('2026-08-16', $this->unrepairableAnnotations(), cachedItems: 2);
        $cachedBefore = $email->processing_metadata[OosArchiveParseCacheBinding::MetadataKey];

        $this->artisan('oos:recompile-archive-parse-cache', ['--item-key' => ['2026-08-16']])
            ->expectsOutputToContain('REGRESSED')
            ->assertExitCode(1);

        $this->assertSame(
            CanonicalJson::hash($cachedBefore),
            CanonicalJson::hash($email->fresh()->processing_metadata[OosArchiveParseCacheBinding::MetadataKey]),
            'A regressing replay must leave the cached result exactly as it found it.',
        );
    }

    #[Test]
    public function allow_regression_writes_the_worse_replay_deliberately(): void
    {
        $email = $this->archiveEmail('2026-08-16', $this->unrepairableAnnotations(), cachedItems: 2);

        $this->artisan('oos:recompile-archive-parse-cache', [
            '--item-key' => ['2026-08-16'],
            '--allow-regression' => true,
        ])->assertExitCode(0);

        $cached = $email->fresh()->processing_metadata[OosArchiveParseCacheBinding::MetadataKey];

        $this->assertSame([], $cached['raw_result']['items']);
    }

    #[Test]
    public function a_dry_run_reports_a_regression_without_writing(): void
    {
        $email = $this->archiveEmail('2026-08-16', $this->unrepairableAnnotations(), cachedItems: 2);
        $cachedBefore = $email->processing_metadata[OosArchiveParseCacheBinding::MetadataKey];

        $this->artisan('oos:recompile-archive-parse-cache', [
            '--item-key' => ['2026-08-16'],
            '--dry-run' => true,
        ])->assertExitCode(1);

        $this->assertSame(
            CanonicalJson::hash($cachedBefore),
            CanonicalJson::hash($email->fresh()->processing_metadata[OosArchiveParseCacheBinding::MetadataKey]),
        );
    }

    #[Test]
    public function an_improving_replay_is_still_written(): void
    {
        // The case the command exists for: banked annotations that compile cleanly today, against a
        // cache that recorded a failure.
        $email = $this->archiveEmail('2026-08-16', $this->cleanAnnotations(), cachedItems: 0);

        $this->artisan('oos:recompile-archive-parse-cache', ['--item-key' => ['2026-08-16']])
            ->assertExitCode(0);

        $cached = $email->fresh()->processing_metadata[OosArchiveParseCacheBinding::MetadataKey];

        $this->assertCount(1, $cached['raw_result']['items']);
    }

    /**
     * An item line with no item kind — `item_semantics_incomplete`, the shape `2020-03-29` and
     * `2025-07-27` replay into once the repairer is out of the loop.
     */
    private function unrepairableAnnotations(): OosSemanticAnnotationResult
    {
        return new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Item, 'morning', null, null, null),
            ],
        );
    }

    private function cleanAnnotations(): OosSemanticAnnotationResult
    {
        return new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song, null, null),
            ],
        );
    }

    private function archiveEmail(string $itemKey, OosSemanticAnnotationResult $annotations, int $cachedItems): InboundEmail
    {
        $email = InboundEmail::query()->create([
            'message_id' => "<{$itemKey}@crockenhill.test>",
            'from' => 'secretary@crockenhill.test',
            'subject' => 'Order of Service',
            'body_plain' => "Morning Service\n248 Immortal, invisible",
            'received_at' => '2026-08-16 09:00:00',
            'status' => InboundEmailStatus::ArchiveEval,
        ]);

        $email->processing_metadata = [
            'archive' => ['item_key' => $itemKey],
            OosArchiveParseCacheBinding::MetadataKey => [
                'version' => 6,
                'raw_result' => [
                    'items' => array_fill(0, $cachedItems, ['type' => 'song', 'title' => 'Immortal, invisible']),
                    'extraction_attempts' => [
                        ['final_rule_codes' => $cachedItems === 0 ? ['item_semantics_incomplete'] : [],
                            'initial_annotations' => $annotations->toArray()],
                    ],
                ],
            ],
        ];
        $email->save();

        return $email;
    }
}
