<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\SpeechSectionClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeechSectionClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_classifies_a_single_type_speech_block_without_manual_review_when_confidence_is_high(): void
    {
        $service = new class extends SpeechSectionClassificationService
        {
            protected function requestClassificationResponse(ServiceSection $section, string $transcript): array
            {
                return [
                    'sections' => [[
                        'section_type' => ServiceSectionType::PRAYER->value,
                        'start_offset_seconds' => 0,
                        'end_offset_seconds' => 120,
                        'confidence' => 0.92,
                        'notes' => ['Opening prayer'],
                        'anomalies' => [],
                    ]],
                ];
            }
        };

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'start_time' => 60.0,
            'end_time' => 180.0,
            'duration' => 120.0,
            'metadata' => [
                'transcript' => 'Let us pray together.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(1, $classified);
        $this->assertSame(ServiceSectionType::PRAYER->value, $classified[0]['section_type']);
        $this->assertFalse($classified[0]['needs_manual_review']);
        $this->assertSame('high', $classified[0]['metadata']['confidence_level']);
        $this->assertSame('ai_transcript', $classified[0]['metadata']['confidence_source']);
    }

    #[Test]
    public function it_splits_a_multi_section_speech_block_using_relative_boundaries(): void
    {
        $service = new class extends SpeechSectionClassificationService
        {
            protected function requestClassificationResponse(ServiceSection $section, string $transcript): array
            {
                return [
                    'sections' => [
                        [
                            'section_type' => ServiceSectionType::WELCOME->value,
                            'start_offset_seconds' => 0,
                            'end_offset_seconds' => 45,
                            'confidence' => 0.9,
                            'notes' => [],
                            'anomalies' => [],
                        ],
                        [
                            'section_type' => ServiceSectionType::PRAYER->value,
                            'start_offset_seconds' => 45,
                            'end_offset_seconds' => 120,
                            'confidence' => 0.88,
                            'notes' => [],
                            'anomalies' => [],
                        ],
                    ],
                ];
            }
        };

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'start_time' => 300.0,
            'end_time' => 420.0,
            'duration' => 120.0,
            'metadata' => [
                'transcript' => 'Welcome everyone. Let us pray.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(2, $classified);
        $this->assertSame(300.0, $classified[0]['start_time']);
        $this->assertSame(345.0, $classified[0]['end_time']);
        $this->assertSame(ServiceSectionType::WELCOME->value, $classified[0]['section_type']);
        $this->assertNotSame('Welcome everyone. Let us pray.', $classified[0]['metadata']['transcript']);
        $this->assertSame('section_excerpt', $classified[0]['metadata']['transcript_scope']);
        $this->assertSame(345.0, $classified[1]['start_time']);
        $this->assertSame(420.0, $classified[1]['end_time']);
        $this->assertSame(ServiceSectionType::PRAYER->value, $classified[1]['section_type']);
        $this->assertNotSame('Welcome everyone. Let us pray.', $classified[1]['metadata']['transcript']);
        $this->assertSame('section_excerpt', $classified[1]['metadata']['transcript_scope']);
    }

    #[Test]
    public function it_downgrades_low_confidence_classification_to_other_and_requires_review(): void
    {
        $service = new class extends SpeechSectionClassificationService
        {
            protected function requestClassificationResponse(ServiceSection $section, string $transcript): array
            {
                return [
                    'sections' => [[
                        'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
                        'start_offset_seconds' => 0,
                        'end_offset_seconds' => 90,
                        'confidence' => 0.45,
                        'notes' => ['Uncertain'],
                        'anomalies' => [],
                    ]],
                ];
            }
        };

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'start_time' => 0.0,
            'end_time' => 90.0,
            'duration' => 90.0,
            'metadata' => [
                'transcript' => 'Maybe this is the children section.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(1, $classified);
        $this->assertSame(ServiceSectionType::OTHER->value, $classified[0]['section_type']);
        $this->assertTrue($classified[0]['needs_manual_review']);
        $this->assertSame('none', $classified[0]['metadata']['confidence_level']);
        $this->assertSame('low_ai_confidence', $classified[0]['metadata']['review_reason']);
    }

    #[Test]
    public function it_builds_the_ai_prompt_using_duration_computed_from_section_boundaries(): void
    {
        $service = new class extends SpeechSectionClassificationService
        {
            public function promptFor(ServiceSection $section): string
            {
                return $this->buildUserPrompt(
                    $section,
                    max(0.0, (float) $section->end_time - (float) $section->start_time),
                    'Transcript body'
                );
            }
        };

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'start_time' => 15.0,
            'end_time' => 75.0,
            'duration' => 5.0,
            'metadata' => [
                'transcript' => 'Transcript body',
            ],
        ]);

        $prompt = $service->promptFor($section);

        $this->assertStringContainsString('Segment duration: 60.00 seconds', $prompt);
        $this->assertStringNotContainsString('Segment duration: 5.00 seconds', $prompt);
    }

    #[Test]
    public function it_classifies_sections_using_openai_without_structured_response_format(): void
    {
        Config::set('media-processing.analysis.service', 'openai');
        Config::set('openai.api_key', 'test-key');

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'sections' => [[
                                'section_type' => ServiceSectionType::WELCOME->value,
                                'start_offset_seconds' => 0,
                                'end_offset_seconds' => 60,
                                'confidence' => 0.91,
                                'notes' => ['Confident welcome boundary.'],
                                'anomalies' => [],
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $service = new SpeechSectionClassificationService;

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'duration' => 60.0,
            'metadata' => [
                'transcript' => 'Good morning everyone and welcome to our service.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(1, $classified);
        $this->assertSame(ServiceSectionType::WELCOME->value, $classified[0]['section_type']);
        $this->assertFalse($classified[0]['needs_manual_review']);
    }

    #[Test]
    public function it_can_decode_json_wrapped_in_markdown_code_fences_from_openai(): void
    {
        Config::set('media-processing.analysis.service', 'openai');
        Config::set('openai.api_key', 'test-key');

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => [
                        'content' => <<<'TEXT'
```json
{"sections":[{"section_type":"prayer","start_offset_seconds":0,"end_offset_seconds":90,"confidence":0.89,"notes":["Opening prayer"],"anomalies":[]}]}
```
TEXT,
                    ],
                ]],
            ]),
        ]);

        $service = new SpeechSectionClassificationService;

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'start_time' => 30.0,
            'end_time' => 120.0,
            'duration' => 90.0,
            'metadata' => [
                'transcript' => 'Let us pray together as we begin our worship.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(1, $classified);
        $this->assertSame(ServiceSectionType::PRAYER->value, $classified[0]['section_type']);
        $this->assertSame(30.0, $classified[0]['start_time']);
        $this->assertSame(120.0, $classified[0]['end_time']);
    }
}
