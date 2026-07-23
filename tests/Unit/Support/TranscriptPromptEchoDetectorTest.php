<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TranscriptPromptEchoDetector;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscriptPromptEchoDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.transcription.prompts', [
            'sermon' => 'The following speech is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.',
            'full_service' => 'The following is a full church service at Crockenhill Baptist Church, in the British conservative evangelical tradition: welcome, hymns and songs, prayers, Bible readings, notices and a sermon.',
        ]);
    }

    #[Test]
    public function it_detects_exact_and_paraphrased_prompt_echoes_without_rejecting_real_content(): void
    {
        $detector = new TranscriptPromptEchoDetector;

        $this->assertTrue($detector->isPromptEcho(
            'The following speech is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.'
        ));
        $this->assertTrue($detector->isPromptEcho(
            'This is a Christian sermon preached at Crockenhill Baptist Church in the British conservative evangelical tradition.'
        ));
        $this->assertFalse($detector->isPromptEcho(
            'Welcome to Crockenhill Baptist Church. This morning we continue our series in Colossians.'
        ));
        $this->assertFalse($detector->isPromptEcho(
            'Christians in the conservative evangelical tradition seek to remain faithful to Scripture.'
        ));
    }
}
