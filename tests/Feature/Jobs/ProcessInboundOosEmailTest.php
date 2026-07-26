<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Jobs\ProcessInboundOosEmail;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\Song;
use App\Queries\ReviewInboxQuery;
use App\Services\Public\PublicSongUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ProcessInboundOosEmailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_a_high_confidence_email_into_a_service(): void
    {
        Event::fake([ChurchServiceCanonicalListChanged::class]);

        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'song', 'title' => 'Before the throne of God above'],
                ['type' => 'prayer', 'title' => 'Opening prayer'],
                ['type' => 'bible_reading', 'title' => 'Luke 15:1-32'],
            ],
            confidence: 0.95,
            services: [[
                'service' => 'morning',
                'date' => '2026-03-15',
                'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'song', 'title' => 'Before the throne of God above'],
                    ['type' => 'prayer', 'title' => 'Opening prayer'],
                    ['type' => 'bible_reading', 'title' => 'Luke 15:1-32'],
                ],
                'confidence' => 0.95,
            ]],
        ));

        $song = Song::factory()->create([
            'canonical_key' => 'before the throne of god above',
            'title' => 'Before the throne of God above',
        ]);

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-03-15 AM',
            'body_plain' => "Welcome\nBefore the throne of God above\nOpening prayer\nLuke 15:1-32",
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-03-13 09:00:00',
        ]);

        app()->call([new ProcessInboundOosEmail($email), 'handle']);

        $service = ChurchService::query()->firstOrFail();

        $this->assertSame('2026-03-15', $service->date->toDateString());
        $this->assertSame(SermonService::Morning, $service->service);
        $this->assertSame('email', $service->source);
        $this->assertFalse($service->needs_review);
        $this->assertCount(4, $service->items()->get());
        $this->assertDatabaseHas('church_service_items', [
            'church_service_id' => $service->id,
            'type' => 'songs',
            'song_id' => $song->id,
            'source' => 'email',
        ]);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertSame($service->id, $email->processing_metadata['imported_church_service_id']);
        $this->assertCount(4, $email->processing_metadata['parsing']['items']);
        $this->assertSame('morning', $email->processing_metadata['parsing']['resolved_service']);
        $this->assertSame('llm', $email->processing_metadata['parsing']['service_extraction']['method']);
        Event::assertDispatched(
            ChurchServiceCanonicalListChanged::class,
            fn (ChurchServiceCanonicalListChanged $event): bool => $event->churchServiceId === $service->id
                && $event->source === 'email'
                && count($event->changes) > 0
        );
        Event::assertDispatchedTimes(ChurchServiceCanonicalListChanged::class, 1);
    }

    #[Test]
    public function it_holds_an_ambiguous_email_for_review_without_importing_it(): void
    {
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'song', 'title' => 'How deep the Father\'s love for us'],
                ['type' => 'sermon', 'title' => 'Sermon'],
            ],
            confidence: 0.85,
            services: [[
                'service' => 'morning',
                'date' => '2026-03-15',
                'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'song', 'title' => 'How deep the Father\'s love for us'],
                    ['type' => 'sermon', 'title' => 'Sermon'],
                ],
                'confidence' => 0.85,
            ]],
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Service plan for 15 March',
            'body_plain' => "10.30am service\nWelcome\nHow deep the Father's love for us\nSermon",
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-03-10 09:00:00',
        ]);

        app()->call([new ProcessInboundOosEmail($email), 'handle']);

        $this->assertDatabaseCount('church_services', 0);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Pending, $email->status);
        $this->assertFalse($email->processing_metadata['parsing']['should_import']);
        $this->assertTrue($email->processing_metadata['parsing']['needs_review']);
        $this->assertTrue($email->processing_metadata['parsing']['confidence_score'] >= 0.75);
        $this->assertTrue($email->processing_metadata['parsing']['confidence_score'] < 0.90);
    }

    #[Test]
    #[DataProvider('eveningEmailSubjects')]
    public function it_imports_evening_oos_emails_and_counts_song_usage_without_a_livestream(
        string $subject,
        string $bodyPlain,
    ): void {
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'song', 'title' => 'There is a higher throne'],
                ['type' => 'prayer', 'title' => 'Pastoral prayer'],
            ],
            confidence: 0.95,
            services: [[
                'service' => 'evening',
                'date' => '2026-03-15',
                'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'song', 'title' => 'There is a higher throne'],
                    ['type' => 'prayer', 'title' => 'Pastoral prayer'],
                ],
                'confidence' => 0.95,
            ]],
        ));

        $song = Song::factory()->create([
            'canonical_key' => 'there is a higher throne',
            'title' => 'There is a higher throne',
        ]);

        $email = InboundEmail::factory()->create([
            'subject' => $subject,
            'body_plain' => $bodyPlain,
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-03-10 09:00:00',
        ]);

        app()->call([new ProcessInboundOosEmail($email), 'handle']);

        /** @var ChurchService $service */
        $service = ChurchService::query()->with(['items.song', 'mediaProcessingLogs'])->sole();
        $songUsage = app(PublicSongUsageService::class);

        $this->assertSame(SermonService::Evening, $service->service);
        $this->assertSame('email', $service->source);
        $this->assertCount(0, $service->mediaProcessingLogs);
        $this->assertCount(3, $service->items);
        $this->assertTrue($service->items->contains(
            fn ($item): bool => $item->song_id === $song->id && $item->type === 'songs'
        ));

        $songStats = $songUsage->statsForSong($song);
        $songHistory = $songUsage->usageHistoryForSong($song);

        $this->assertSame(1, $songStats['usage_count']);
        $this->assertSame($service->date->toDateString(), $songStats['last_sung_date']);
        $this->assertCount(1, $songHistory);
        $this->assertSame($service->id, $songHistory->sole()->church_service_id);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertSame('evening', $email->processing_metadata['parsing']['resolved_service']);
        $this->assertSame('llm', $email->processing_metadata['parsing']['service_extraction']['method']);
    }

    #[Test]
    public function it_leaves_low_confidence_email_pending_without_creating_a_service(): void
    {
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.10,
            notes: ['Could not identify any OoS items.'],
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'hello there',
            'body_plain' => 'just checking in',
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-03-10 09:00:00',
        ]);

        app()->call([new ProcessInboundOosEmail($email), 'handle']);

        $this->assertDatabaseCount('church_services', 0);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Pending, $email->status);
        $this->assertLessThan(0.75, $email->processing_metadata['parsing']['confidence_score']);
        $this->assertSame([], $email->processing_metadata['parsing']['items']);
    }

    #[Test]
    public function it_marks_the_email_as_failed_when_the_job_fails(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
        ]);

        $job = new ProcessInboundOosEmail($email);
        $job->failed(new RuntimeException('Parser exploded'));

        $email->refresh();

        $this->assertSame(InboundEmailStatus::Failed, $email->status);

        $failure = $email->processing_metadata['failure'];
        $this->assertSame('Parser exploded', $failure['message']);
        $this->assertSame(RuntimeException::class, $failure['exception_class']);
        $this->assertArrayHasKey('attempt', $failure);
        $this->assertArrayHasKey('queue_name', $failure);
        $this->assertArrayHasKey('failed_at', $failure);
    }

    #[Test]
    public function an_llm_error_reaches_the_queue_failure_path_and_manual_review_inbox(): void
    {
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                throw new RuntimeException('OpenAI unavailable');
            }
        });

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
        ]);
        $job = new ProcessInboundOosEmail($email);

        try {
            app()->call([$job, 'handle']);
            $this->fail('The extractor error should be re-thrown for the queue to retry.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $email->refresh();

        $this->assertSame(InboundEmailStatus::Failed, $email->status);
        $this->assertSame('OpenAI unavailable', $email->processing_metadata['failure']['message']);
        $this->assertSame(1, app(ReviewInboxQuery::class)->build()['counts']['emails']);
    }

    private function bindExtractor(OosEmailItemExtractionResult $result): void
    {
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class($result) implements OosEmailItemExtractor
        {
            public function __construct(
                private readonly OosEmailItemExtractionResult $result,
            ) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return $this->result;
            }
        });
    }

    /**
     * @return array<string, array{subject:string, bodyPlain:string}>
     */
    public static function eveningEmailSubjects(): array
    {
        return [
            'explicit evening wording' => [
                'subject' => 'Order of Service - Sunday 15 March 2026 evening',
                'bodyPlain' => "Welcome\nThere is a higher throne\nPastoral prayer",
            ],
            'pm time hint in body' => [
                'subject' => 'Order of Service - Sunday 15 March 2026',
                'bodyPlain' => "6pm service\nWelcome\nThere is a higher throne\nPastoral prayer",
            ],
        ];
    }
}
