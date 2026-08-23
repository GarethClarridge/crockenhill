<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCorpusMembership;
use App\Services\ChurchService\ChurchServiceProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The producer of the census gate's membership certificate.
 *
 * The certificate has always been able to span source kinds — every item carries
 * its own `source` and `batch_hash`, and {@see ChurchServiceCorpusMembership}
 * reports the distinct kinds it found so the gate can refuse one that misses a
 * declared kind. Only this command was single-lane, which is why a census declaring
 * `email,openlp` could not be assembled at all.
 */
class GenerateChurchServiceCorpusMembershipCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EmailBatchHash = 'aa11bb22cc33dd44ee55ff66aa77bb88cc99dd00ee11ff22aa33bb44cc55dd66';

    private const OpenLpBatchHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[Test]
    public function one_certificate_spans_every_named_lane(): void
    {
        $this->stage(ChurchServiceSource::Email, self::EmailBatchHash);
        $this->stage(ChurchServiceSource::OpenLp, self::OpenLpBatchHash);

        $membership = $this->generate([
            '--source' => ['email', 'openlp'],
            '--batch-hash' => [self::EmailBatchHash, self::OpenLpBatchHash],
        ]);

        $this->assertSame(
            ['email', 'openlp'],
            array_values(array_unique(array_column($membership['items'], 'source'))),
        );

        $certified = app(ChurchServiceCorpusMembership::class)->certify(
            $membership,
            ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        );

        $this->assertTrue($certified['approved']);
        $this->assertSame(['email', 'openlp'], $certified['source_kinds']);
        $this->assertSame([], $certified['blockers']);
    }

    /**
     * The hash identifies the certified set, so naming the same two lanes in the
     * other order has to produce the same certificate. Otherwise an operator could
     * be handed two artifacts that disagree on their hash while certifying exactly
     * the same corpus.
     */
    #[Test]
    public function the_certificate_hash_does_not_depend_on_the_order_the_lanes_were_named(): void
    {
        $this->stage(ChurchServiceSource::Email, self::EmailBatchHash);
        $this->stage(ChurchServiceSource::OpenLp, self::OpenLpBatchHash);

        $forwards = $this->generate([
            '--source' => ['email', 'openlp'],
            '--batch-hash' => [self::EmailBatchHash, self::OpenLpBatchHash],
        ]);
        $backwards = $this->generate([
            '--source' => ['openlp', 'email'],
            '--batch-hash' => [self::OpenLpBatchHash, self::EmailBatchHash],
        ]);

        $this->assertSame($forwards['membership_hash'], $backwards['membership_hash']);
    }

    /**
     * Zipping a short pair would drop a lane silently and produce a certificate that
     * looks complete, so a mismatch is refused instead.
     */
    #[Test]
    public function a_lane_without_its_own_batch_hash_is_refused(): void
    {
        $this->stage(ChurchServiceSource::Email, self::EmailBatchHash);

        $this->artisan('services:generate-corpus-membership', [
            '--source' => ['email', 'openlp'],
            '--batch-hash' => [self::EmailBatchHash],
        ])
            ->expectsOutputToContain('Each source needs its own batch hash')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_lane_with_nothing_staged_names_the_lane_it_could_not_find(): void
    {
        $this->stage(ChurchServiceSource::Email, self::EmailBatchHash);

        $this->artisan('services:generate-corpus-membership', [
            '--source' => ['email', 'openlp'],
            '--batch-hash' => [self::EmailBatchHash, self::OpenLpBatchHash],
        ])
            ->expectsOutputToContain('for source openlp')
            ->assertExitCode(1);
    }

    #[Test]
    public function naming_one_lane_twice_is_refused(): void
    {
        $this->stage(ChurchServiceSource::Email, self::EmailBatchHash);

        $this->artisan('services:generate-corpus-membership', [
            '--source' => ['email', 'email'],
            '--batch-hash' => [self::EmailBatchHash, self::OpenLpBatchHash],
        ])
            ->expectsOutputToContain('is named twice')
            ->assertExitCode(1);
    }

    /** @return array<string, mixed> */
    private function generate(array $options): array
    {
        $path = storage_path('scratch/membership-'.bin2hex(random_bytes(6)).'.json');
        $this->artisan('services:generate-corpus-membership', [...$options, '--output' => $path])
            ->assertExitCode(0);

        /** @var array<string, mixed> $membership */
        $membership = json_decode((string) file_get_contents($path), true);
        unlink($path);

        return $membership;
    }

    private function stage(ChurchServiceSource $source, string $batchHash): void
    {
        $service = ChurchService::factory()->create([
            'date' => now()->subWeeks($source === ChurchServiceSource::Email ? 1 : 2)->toDateString(),
            'service' => 'morning',
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        ]);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => $source,
            'batch_hash' => $batchHash,
        ]);
    }
}
