<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScrubPromptEchoSectionsCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function dry_run_writes_nothing_and_apply_removes_the_echo(): void
    {
        $section = $this->echoSection();

        $this->artisan('service:scrub-prompt-echo-sections')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('No changes written')
            ->assertSuccessful();
        $this->assertModelExists($section);

        $this->artisan('service:scrub-prompt-echo-sections --apply')
            ->expectsOutputToContain('APPLYING')
            ->assertSuccessful();
        $this->assertModelMissing($section);
    }

    #[Test]
    public function service_scope_and_superseded_flag_are_respected(): void
    {
        $target = $this->echoSection();
        $other = $this->echoSection();
        $superseded = $this->echoSection(superseded: true);

        $this->artisan('service:scrub-prompt-echo-sections --apply --service='.$target->processingLog->church_service_id)
            ->assertSuccessful();

        $this->assertModelMissing($target);
        $this->assertModelExists($other);
        $this->assertModelExists($superseded);

        $this->artisan('service:scrub-prompt-echo-sections --apply --include-superseded')
            ->assertSuccessful();
        $this->assertModelMissing($superseded);
    }

    /**
     * A hold is the only record that an operator proved this content wrong; scrubbing
     * the section would delete it along with the row.
     */
    #[Test]
    public function a_held_section_is_kept_and_named(): void
    {
        $section = $this->echoSection(sectionType: ServiceSectionType::Sermon);
        app(HoldSectionForContentReview::class)($section, 'Saved text repeats a sentence the audio does not', 'plan §4.1a', ContentHoldCheck::Judgement);

        $this->artisan('service:scrub-prompt-echo-sections --apply')
            ->expectsOutputToContain('Kept 1 held section(s)')
            ->expectsOutputToContain('Removed 0 section(s)')
            ->assertSuccessful();

        $this->assertModelExists($section);
    }

    private function echoSection(bool $superseded = false, ServiceSectionType $sectionType = ServiceSectionType::Other): ServiceSection
    {
        $service = ChurchService::factory()->create();
        $run = MediaProcessingLog::factory()->livestream()->failed()->create([
            'church_service_id' => $service->id,
            'superseded_at' => $superseded ? now() : null,
        ]);

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => $sectionType,
            'metadata' => [
                'transcript' => 'This is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.',
            ],
        ]);
    }
}
