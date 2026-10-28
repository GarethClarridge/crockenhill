<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Support\RuledEndingsTestCase;

class RuledEndingsHarnessTest extends RuledEndingsTestCase
{
    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function planner_writes_are_rolled_back_before_the_next_output_even_on_failure(bool $throws): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'needs_manual_review' => false]);
        $originalMetadata = $log->getRawOriginal('processing_metadata');
        $level = $log->getConnection()->transactionLevel();

        $resolver = Mockery::mock(SermonExtractionPlanResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturnUsing(function (MediaProcessingLog $working) use ($section, $throws): array {
            $working->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata, 'planner_test' => true]);
            $section->update(['needs_manual_review' => true]);
            if ($throws) {
                throw new RuntimeException('Planner failed after writing');
            }

            return ['segments' => [['start_time' => 1.0, 'end_time' => 2.0]]];
        });
        $this->app->instance(SermonExtractionPlanResolver::class, $resolver);

        try {
            $plan = $this->planForRuling($log, null);
            $this->assertFalse($throws, 'The failing planner must throw.');
            $this->assertSame(1.0, $plan['segments'][0]['start_time']);
        } catch (RuntimeException $exception) {
            $this->assertTrue($throws);
            $this->assertSame('Planner failed after writing', $exception->getMessage());
        }

        $this->assertSame($level, $log->getConnection()->transactionLevel());
        $this->assertSame($originalMetadata, $log->fresh()->getRawOriginal('processing_metadata'));
        $this->assertFalse($section->fresh()->needs_manual_review);

        $planner = Mockery::mock(CueSafeExtractionPlan::class);
        $planner->shouldReceive('forSection')->once()->andReturnUsing(function (ServiceSection $fresh): array {
            $this->assertFalse($fresh->needs_manual_review);
            $this->assertArrayNotHasKey('planner_test', $fresh->processingLog->processing_metadata->raw);

            return ['segments' => []];
        });
        $this->app->instance(CueSafeExtractionPlan::class, $planner);

        $this->assertSame(['segments' => []], $this->planForRuling($log, $section->id));
    }
}
