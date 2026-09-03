<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceOccasion;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * D1/D2/D3, 2026-09-03: the detector may propose that a service held no sermon
 * and name the occasion; only an operator's confirmation releases that label to
 * a visitor, and until it comes the run keeps its source so the recording can
 * still be watched.
 */
class ServiceOccasionConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['church.services.public_from' => '2000-01-01']);
    }

    #[Test]
    public function an_unconfirmed_absence_assertion_retains_the_run_source(): void
    {
        [$service, $run] = $this->missionPresentationEvening();

        $this->assertContains('sermon_absence_unconfirmed', $run->reviewSourceRetentionReasons());
        $this->assertTrue($run->hasUnresolvedReviewObligation());
        $this->assertTrue(
            MediaProcessingLog::query()->withUnresolvedReviewObligation()->whereKey($run->id)->exists()
        );

        $service->forceFill(['occasion_confirmed_at' => now()])->saveQuietly();

        $this->assertSame([], $run->fresh()?->reviewSourceRetentionReasons());
        $this->assertFalse(
            MediaProcessingLog::query()->withUnresolvedReviewObligation()->whereKey($run->id)->exists()
        );
    }

    #[Test]
    public function a_run_with_no_absence_assertion_holds_no_occasion_obligation(): void
    {
        [$service] = $this->missionPresentationEvening();

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $service->id,
            'processing_metadata' => ['service_structure' => ['sections' => []]],
        ]);

        $this->assertNotContains('sermon_absence_unconfirmed', $run->reviewSourceRetentionReasons());
    }

    #[Test]
    public function the_confirm_command_is_a_dry_run_by_default(): void
    {
        [$service] = $this->missionPresentationEvening();

        $this->artisan('services:confirm-occasion', [
            '--date' => '2024-02-11',
            '--service' => 'evening',
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('A visiting mission presented its work for the whole evening.')
            ->assertSuccessful();

        $this->assertNull($service->fresh()?->occasion_confirmed_at);
    }

    #[Test]
    public function the_confirm_command_requires_yes_alongside_apply(): void
    {
        $this->missionPresentationEvening();

        $this->artisan('services:confirm-occasion', [
            '--date' => '2024-02-11',
            '--service' => 'evening',
            '--apply' => true,
        ])->assertFailed();
    }

    #[Test]
    public function confirming_defaults_to_the_proposed_occasion(): void
    {
        [$service] = $this->missionPresentationEvening();

        $this->artisan('services:confirm-occasion', $this->applyArgs())->assertSuccessful();

        $service->refresh();

        $this->assertSame(ServiceOccasion::MissionPresentation, $service->occasion);
        $this->assertNotNull($service->occasion_confirmed_at);
        $this->assertSame(ServiceOccasion::MissionPresentation, $service->confirmedOccasion());
    }

    #[Test]
    public function an_operator_may_confirm_a_different_occasion_from_the_one_proposed(): void
    {
        [$service] = $this->missionPresentationEvening();

        $this->artisan('services:confirm-occasion', $this->applyArgs(['--occasion' => 'carol_service']))
            ->assertSuccessful();

        $this->assertSame(ServiceOccasion::CarolService, $service->fresh()?->occasion);
    }

    /**
     * "No special occasion" is a real answer — the detector misread the
     * recording — and it still resolves the obligation.
     */
    #[Test]
    public function an_operator_may_confirm_that_there_is_no_special_occasion(): void
    {
        [$service, $run] = $this->missionPresentationEvening();

        $this->artisan('services:confirm-occasion', $this->applyArgs(['--occasion' => 'none']))
            ->assertSuccessful();

        $service->refresh();

        $this->assertNull($service->occasion);
        $this->assertNotNull($service->occasion_confirmed_at);
        $this->assertSame([], $run->fresh()?->reviewSourceRetentionReasons());
    }

    #[Test]
    public function the_confirm_command_refuses_an_unknown_occasion(): void
    {
        [$service] = $this->missionPresentationEvening();

        $this->artisan('services:confirm-occasion', $this->applyArgs(['--occasion' => 'harvest_supper']))
            ->expectsOutputToContain('is not a known occasion')
            ->assertFailed();

        $this->assertNull($service->fresh()?->occasion_confirmed_at);
    }

    #[Test]
    public function the_public_page_hides_an_unconfirmed_occasion_and_shows_a_confirmed_one(): void
    {
        [$service] = $this->missionPresentationEvening();

        $this->get($this->serviceUrl($service))
            ->assertOk()
            ->assertDontSee('Mission presentation');

        $service->forceFill(['occasion_confirmed_at' => now()])->saveQuietly();

        $this->get($this->serviceUrl($service))
            ->assertOk()
            ->assertSee('Mission presentation');
    }

    /**
     * The hardcoded "order of service, sermon, songs and readings" is simply
     * false for an evening that held no sermon.
     */
    #[Test]
    public function the_meta_description_does_not_promise_a_sermon_a_service_never_had(): void
    {
        [$service] = $this->missionPresentationEvening();

        $this->get($this->serviceUrl($service))
            ->assertOk()
            ->assertSee('The order of service, songs and readings', escape: false)
            ->assertDontSee('The order of service, sermon, songs and readings', escape: false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applyArgs(array $overrides = []): array
    {
        return [
            '--date' => '2024-02-11',
            '--service' => 'evening',
            '--apply' => true,
            '--yes' => true,
            ...$overrides,
        ];
    }

    private function serviceUrl(ChurchService $service): string
    {
        return route('church.services.show', [
            'date' => $service->date->format('Y-m-d'),
            'service' => $service->service->value,
        ]);
    }

    /**
     * The shape of the 2024-02-11 evening: a visiting mission's presentation
     * with no sermon anywhere in the recording, but real public content of its
     * own — without which the service would have no public page at all.
     *
     * @return array{0: ChurchService, 1: MediaProcessingLog}
     */
    private function missionPresentationEvening(): array
    {
        $service = ChurchService::factory()->create([
            'date' => '2024-02-11',
            'service' => SermonService::Evening,
            'occasion' => ServiceOccasion::MissionPresentation,
            'occasion_confirmed_at' => null,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'type' => 'bibles',
            'title' => 'Psalm 67',
            'position' => 1,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'processing_metadata' => [
                'service_structure' => [
                    'sections' => [],
                    'sermon_absence' => [
                        'occasion' => 'mission_presentation',
                        'explanation' => 'A visiting mission presented its work for the whole evening.',
                    ],
                ],
            ],
        ]);

        return [$service, $run];
    }
}
