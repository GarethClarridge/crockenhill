<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCompatibilityMergeDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceCompatibilityMergeDecisionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_service_without_normalized_evidence_uses_the_compatibility_merge(): void
    {
        $this->assertTrue($this->decision()->usesCompatibilityMerge(ChurchService::factory()->create()));
    }

    #[Test]
    public function a_service_holding_livestream_evidence_uses_the_compatibility_merge(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Livestream,
        ]);

        $this->assertTrue($this->decision()->usesCompatibilityMerge($service));
    }

    /**
     * The projection persister writes `church_services.source` from its own source
     * summary. Keying the decision on that column let a service the new pipeline
     * had just projected fall straight back onto the compatibility path.
     */
    #[Test]
    public function the_decision_ignores_the_source_column_the_projector_writes(): void
    {
        $service = ChurchService::factory()->create([
            'source' => ChurchServiceItemSource::Livestream->value,
        ]);
        ChurchServiceSourceRecord::factory()->for($service, 'churchService')->create([
            'source' => ChurchServiceSource::Email,
        ]);

        $this->assertFalse($this->decision()->usesCompatibilityMerge($service));
    }

    private function decision(): ChurchServiceCompatibilityMergeDecision
    {
        return app(ChurchServiceCompatibilityMergeDecision::class);
    }
}
