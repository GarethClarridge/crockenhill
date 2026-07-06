<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TranscriptionServiceInterface;
use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Media\Audio\AudioTranscriptionService;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonAnalysisService;
use App\Services\Sermon\SermonCreationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\MediaProcessingTestHelpers;

class SermonProcessingJobChainTest extends TestCase
{
    use MediaProcessingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMediaDisks();

        config([
            'media-processing.transcription.openai_api_key' => 'test-key',
            'media-processing.analysis.openai_api_key' => 'test-key',
            'media-processing.processing.queue' => 'default',
            'openai.api_key' => 'test-key', // Add this for the OpenAI Laravel package
        ]);
    }


}
