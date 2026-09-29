<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\EnsembleReviewGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsembleReviewGateTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function complete_evidence_cannot_authorise_extraction_after_policy_or_artifact_drift(): void
    {
        Config::set('media-processing.storage.service_artifact_disk', 'local');
        Storage::fake('local');
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $inputPath = 'service-transcripts/ensemble-gate.input.json';
        $input = json_encode([
            'source' => [
                'church_service_id' => $log->church_service_id,
                'transcript_path' => null,
                'audio_timeline_path' => null,
                'rms_log_path' => null,
                'transcript_hash' => null,
                'audio_timeline_hash' => null,
                'rms_log_hash' => null,
            ],
            'oos_items' => [],
            'validation_context' => ['recording_omits_songs' => false],
        ], JSON_THROW_ON_ERROR);
        Storage::disk('local')->put($inputPath, $input);
        $hash = hash('sha256', $input);
        $slots = [];

        for ($number = 0; $number < 4; $number++) {
            $model = $number < 2 ? 'gpt-5.6-luna' : 'gpt-6-luna';
            $path = "service-transcripts/ensemble-gate.slot-{$number}.json";
            $rawSlot = json_encode([
                'status' => 'valid',
                'input_hash' => $hash,
                'model' => $model,
                'artifact_disk' => 'local',
            ], JSON_THROW_ON_ERROR);
            Storage::disk('local')->put($path, $rawSlot);
            $slots[] = ['slot' => $number, 'status' => 'valid', 'path' => $path, 'model' => $model, 'sha256' => hash('sha256', $rawSlot)];
        }

        $log->forceFill(['processing_metadata' => [
            'service_structure_ensemble' => [[
                'input_path' => $inputPath,
                'input_hash' => $hash,
                'artifact_disk' => 'local',
                'slots' => $slots,
                'outcomes' => $slots,
                'composition' => ['validation_passed' => true, 'degraded' => false, 'disputes' => []],
            ]],
        ]])->save();

        $gate = app(EnsembleReviewGate::class);
        $this->assertFalse($gate->requiresReview($log->fresh()));

        $metadata = $log->processing_metadata->toArray();
        $metadata['service_structure_ensemble'][0]['outcomes'][0]['status'] = 'invalid';
        $metadata['service_structure_ensemble'][0]['composition']['degraded'] = true;
        $invalidSlot = json_encode([
            'status' => 'invalid',
            'input_hash' => $hash,
            'model' => $slots[0]['model'],
            'artifact_disk' => 'local',
        ], JSON_THROW_ON_ERROR);
        Storage::disk('local')->put($slots[0]['path'], $invalidSlot);
        $metadata['service_structure_ensemble'][0]['outcomes'][0]['sha256'] = hash('sha256', $invalidSlot);
        $metadata['service_structure_ensemble'][0]['slots'][0]['sha256'] = hash('sha256', $invalidSlot);
        $log->forceFill(['processing_metadata' => $metadata])->save();
        $this->assertTrue($gate->requiresReview($log->fresh()));

        $metadata['service_structure_ensemble'][0]['composition']['degraded_reviewed'] = true;
        $log->forceFill(['processing_metadata' => $metadata])->save();
        $this->assertFalse($gate->requiresReview($log->fresh()));

        $log->forceFill(['processing_metadata' => [
            ...$log->processing_metadata->toArray(),
            'historic_import' => ['concatenation' => 'fragments'],
        ]])->save();
        $this->assertTrue($gate->requiresReview($log->fresh()));

        $log->forceFill(['processing_metadata' => [
            'service_structure_ensemble' => $log->processing_metadata->toArray()['service_structure_ensemble'],
        ]])->save();
        Storage::disk('local')->delete($slots[0]['path']);
        $this->assertTrue($gate->requiresReview($log->fresh()));
    }
}
