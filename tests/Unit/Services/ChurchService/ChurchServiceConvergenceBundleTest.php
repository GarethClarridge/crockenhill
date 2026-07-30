<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Services\ChurchService\ChurchServiceConvergenceBundle;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ChurchServiceConvergenceBundleTest extends TestCase
{
    #[Test]
    public function it_hashes_a_portable_reviewed_convergence_bundle(): void
    {
        $bundle = (new ChurchServiceConvergenceBundle)->make(
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['projector_version' => 1],
            [$this->service()],
        );

        $this->assertSame(ChurchServiceConvergenceBundle::FORMAT, $bundle['format']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $bundle['bundle_hash']);
    }

    #[Test]
    public function it_rejects_payload_drift_duplicate_services_and_local_ids(): void
    {
        $bundles = new ChurchServiceConvergenceBundle;
        $bundle = $bundles->make(
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['projector_version' => 1],
            [$this->service()],
        );
        $bundle['services'][0]['canonical_manifest']['local_id'] = 42;

        $this->expectException(RuntimeException::class);

        $bundles->validate($bundle);
    }

    /** @return array<string, mixed> */
    private function service(): array
    {
        return [
            'date' => '2026-08-02',
            'service' => 'morning',
            'evidence_set_hash' => str_repeat('3', 64),
            'pre_review_hash' => str_repeat('4', 64),
            'resulting_canonical_hash' => str_repeat('5', 64),
            'manual_revision' => ['source_key' => 'review:uuid', 'assertions' => []],
            'review' => ['review_uuid' => 'uuid', 'reviewer_email_hash' => str_repeat('6', 64), 'decisions' => []],
            'canonical_manifest' => ['items' => []],
        ];
    }
}
