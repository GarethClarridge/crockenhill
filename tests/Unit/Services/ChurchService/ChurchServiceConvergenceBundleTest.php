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

    #[Test]
    public function it_requires_every_service_to_declare_how_it_was_finalised(): void
    {
        $service = $this->service();
        unset($service['finalization']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is missing finalization');

        (new ChurchServiceConvergenceBundle)->make(
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['projector_version' => 1],
            [$service],
        );
    }

    #[Test]
    public function it_rejects_an_automatic_service_that_still_carries_review_data(): void
    {
        $service = [...$this->service(), 'finalization' => 'automatic'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot contain Manual review data');

        (new ChurchServiceConvergenceBundle)->make(
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['projector_version' => 1],
            [$service],
        );
    }

    #[Test]
    public function it_rejects_a_service_without_a_valid_projection_policy_fingerprint(): void
    {
        $service = [...$this->service(), 'projection_policy' => ['format' => 'church-service-projection']];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no valid projection policy fingerprint');

        (new ChurchServiceConvergenceBundle)->make(
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['projector_version' => 1],
            [$service],
        );
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
            'finalization' => 'manual',
            'projection_policy' => ['format' => 'church-service-projection', 'version' => 1],
            'manual_revision' => ['source_key' => 'review:uuid', 'assertions' => []],
            'review' => ['review_uuid' => 'uuid', 'reviewer_email_hash' => str_repeat('6', 64), 'decisions' => []],
            'canonical_manifest' => ['items' => []],
        ];
    }
}
