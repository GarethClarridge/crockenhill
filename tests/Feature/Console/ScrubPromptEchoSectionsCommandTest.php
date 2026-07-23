<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

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

    private function echoSection(bool $superseded = false): ServiceSection
    {
        $service = ChurchService::factory()->create();
        $run = MediaProcessingLog::factory()->livestream()->failed()->create([
            'church_service_id' => $service->id,
            'superseded_at' => $superseded ? now() : null,
        ]);

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other,
            'metadata' => [
                'transcript' => 'This is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.',
            ],
        ]);
    }
}
