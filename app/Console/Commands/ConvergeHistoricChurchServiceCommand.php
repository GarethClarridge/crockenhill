<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ChurchServiceConvergenceBundle;
use App\Services\ChurchService\ConvergeHistoricChurchService;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Temporary R8 production one-shot. Delete after every approved service has
 * converged, the exact no-op rerun passes and the rollback window has expired.
 */
class ConvergeHistoricChurchServiceCommand extends Command
{
    protected $signature = 'service-tracking:converge-historic-service
        {media-bundle : Private Bundle A JSON file}
        {convergence-bundle : Private Bundle B JSON file}
        {--media-index=0 : Zero-based Bundle A service index}
        {--convergence-index=0 : Zero-based Bundle B service index}
        {--apply : Execute the atomic database and asset operation}
        {--plan-hash= : Exact hash printed by the dry run}';

    protected $description = 'Preflight or atomically apply one historic church-service convergence';

    public function handle(
        HistoricProcessingResultBundle $mediaBundles,
        ChurchServiceConvergenceBundle $convergenceBundles,
        ConvergeHistoricChurchService $converge,
    ): int {
        try {
            $mediaBundle = $mediaBundles->validate($this->readBundle((string) $this->argument('media-bundle')));
            $convergenceBundle = $convergenceBundles->validate(
                $this->readBundle((string) $this->argument('convergence-bundle')),
            );
            $mediaIndex = $this->index('media-index');
            $convergenceIndex = $this->index('convergence-index');
            $this->assertServiceIdentity($mediaBundle, $convergenceBundle, $mediaIndex, $convergenceIndex);
            $planHash = CanonicalJson::hash([
                'media_bundle_hash' => $mediaBundle['bundle_hash'],
                'convergence_bundle_hash' => $convergenceBundle['bundle_hash'],
                'media_index' => $mediaIndex,
                'convergence_index' => $convergenceIndex,
            ]);

            if (! $this->option('apply')) {
                $this->info('Historic church-service convergence bundle pair is valid.');
                $this->line("Plan hash: {$planHash}");
                $this->line('No data or assets were changed.');

                return self::SUCCESS;
            }

            $this->assertPlanHash($planHash);
            $result = $converge->execute(
                $mediaBundle,
                $convergenceBundle,
                $mediaIndex,
                $convergenceIndex,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $service = $result['church_service'];
        $this->info("Converged {$service->date->toDateString()} {$service->service->value}.");
        $this->line("Canonical hash: {$service->canonical_hash}");
        $this->line('Created assets: '.count($result['created_assets']));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function readBundle(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Bundle file is missing: {$path}");
        }

        $bundle = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($bundle)) {
            throw new RuntimeException("Bundle must contain a JSON object: {$path}");
        }

        return $bundle;
    }

    private function index(string $option): int
    {
        $value = filter_var($this->option($option), FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < 0) {
            throw new RuntimeException("--{$option} must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     */
    private function assertServiceIdentity(
        array $mediaBundle,
        array $convergenceBundle,
        int $mediaIndex,
        int $convergenceIndex,
    ): void {
        $media = $mediaBundle['services'][$mediaIndex] ?? null;
        $convergence = $convergenceBundle['services'][$convergenceIndex] ?? null;

        if (
            ! is_array($media)
            || ! is_array($convergence)
            || $media['date'] !== $convergence['date']
            || $media['service'] !== $convergence['service']
            || $convergenceBundle['media_bundle_hash'] !== $mediaBundle['bundle_hash']
            || $convergenceBundle['batch_hash'] !== $mediaBundle['batch_hash']
            || CanonicalJson::hash($convergenceBundle['processing_fingerprint'])
                !== CanonicalJson::hash($mediaBundle['processing_fingerprint'])
            || $media['evidence_set_hash'] !== $convergence['evidence_set_hash']
            || $media['pre_review_hash'] !== $convergence['pre_review_hash']
        ) {
            throw new RuntimeException('Bundle A and Bundle B do not identify the same approved service result.');
        }
    }

    private function assertPlanHash(string $expected): void
    {
        $actual = $this->option('plan-hash');

        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('--apply requires the exact --plan-hash printed by the dry run.');
        }
    }
}
