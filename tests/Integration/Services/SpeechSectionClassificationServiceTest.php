<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\SpeechSectionClassificationService;
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
            protected function requestClassificationResponse(ServiceSection $section, string $transcript, array $serviceContext = []): array
            {
                return [
                    'sections' => [[
                        'section_type' => ServiceSectionType::Prayer->value,
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
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 60.0,
            'end_time' => 180.0,
            'duration' => 120.0,
            'metadata' => [
                'transcript' => 'Let us pray together.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(1, $classified);
        $this->assertSame(ServiceSectionType::Prayer->value, $classified[0]['section_type']);
        $this->assertFalse($classified[0]['needs_manual_review']);
        $this->assertSame('high', $classified[0]['metadata']['confidence_level']);
        $this->assertSame('ai_transcript', $classified[0]['metadata']['confidence_source']);
    }

    #[Test]
    public function it_splits_a_multi_section_speech_block_using_relative_boundaries(): void
    {
        $service = new class extends SpeechSectionClassificationService
        {
            protected function requestClassificationResponse(ServiceSection $section, string $transcript, array $serviceContext = []): array
            {
                return [
                    'sections' => [
                        [
                            'section_type' => ServiceSectionType::Welcome->value,
                            'start_offset_seconds' => 0,
                            'end_offset_seconds' => 45,
                            'confidence' => 0.9,
                            'notes' => [],
                            'anomalies' => [],
                        ],
                        [
                            'section_type' => ServiceSectionType::Prayer->value,
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
            'section_type' => ServiceSectionType::Other->value,
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
        $this->assertSame(ServiceSectionType::Welcome->value, $classified[0]['section_type']);
        $this->assertNotSame('Welcome everyone. Let us pray.', $classified[0]['metadata']['transcript']);
        $this->assertSame('section_excerpt', $classified[0]['metadata']['transcript_scope']);
        $this->assertSame(345.0, $classified[1]['start_time']);
        $this->assertSame(420.0, $classified[1]['end_time']);
        $this->assertSame(ServiceSectionType::Prayer->value, $classified[1]['section_type']);
        $this->assertNotSame('Welcome everyone. Let us pray.', $classified[1]['metadata']['transcript']);
        $this->assertSame('section_excerpt', $classified[1]['metadata']['transcript_scope']);
    }

    #[Test]
    public function it_downgrades_low_confidence_classification_to_other_and_requires_review(): void
    {
        $service = new class extends SpeechSectionClassificationService
        {
            protected function requestClassificationResponse(ServiceSection $section, string $transcript, array $serviceContext = []): array
            {
                return [
                    'sections' => [[
                        'section_type' => ServiceSectionType::ChildrensTalk->value,
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
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 0.0,
            'end_time' => 90.0,
            'duration' => 90.0,
            'metadata' => [
                'transcript' => 'Maybe this is the children section.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(1, $classified);
        $this->assertSame(ServiceSectionType::Other->value, $classified[0]['section_type']);
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
            'section_type' => ServiceSectionType::Other->value,
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
    public function it_includes_sermon_context_in_the_user_prompt_when_a_sermon_already_exists(): void
    {
        $service = new class extends SpeechSectionClassificationService
        {
            public function promptFor(ServiceSection $section, array $serviceContext): string
            {
                return $this->buildUserPrompt(
                    $section,
                    max(0.0, (float) $section->end_time - (float) $section->start_time),
                    'Transcript body',
                    $serviceContext
                );
            }
        };

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 100.0,
            'end_time' => 700.0,
            'duration' => 600.0,
            'metadata' => ['transcript' => 'Transcript body'],
        ]);

        $context = [
            'sermon_count' => 1,
            'sermon_duration_seconds' => 1800.0,
            'sermon_start_time' => 2500.0,
            'sermon_end_time' => 4300.0,
        ];

        $prompt = $service->promptFor($section, $context);

        $this->assertStringContainsString('sermon section of 1800s', $prompt);
        $this->assertStringContainsString('2500', $prompt);
        $this->assertStringContainsString('4300', $prompt);
    }

    #[Test]
    public function it_omits_sermon_context_from_the_user_prompt_when_no_sermon_exists(): void
    {
        $service = new class extends SpeechSectionClassificationService
        {
            public function promptFor(ServiceSection $section, array $serviceContext): string
            {
                return $this->buildUserPrompt(
                    $section,
                    max(0.0, (float) $section->end_time - (float) $section->start_time),
                    'Transcript body',
                    $serviceContext
                );
            }
        };

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 0.0,
            'end_time' => 120.0,
            'duration' => 120.0,
            'metadata' => ['transcript' => 'Transcript body'],
        ]);

        $prompt = $service->promptFor($section, []);

        $this->assertStringNotContainsString('sermon section', $prompt);
        $this->assertStringNotContainsString('Service context', $prompt);
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
                                'section_type' => ServiceSectionType::Welcome->value,
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
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'duration' => 60.0,
            'metadata' => [
                'transcript' => 'Good morning everyone and welcome to our service.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(1, $classified);
        $this->assertSame(ServiceSectionType::Welcome->value, $classified[0]['section_type']);
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
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 30.0,
            'end_time' => 120.0,
            'duration' => 90.0,
            'metadata' => [
                'transcript' => 'Let us pray together as we begin our worship.',
            ],
        ]);

        $classified = $service->classify($section);

        $this->assertCount(1, $classified);
        $this->assertSame(ServiceSectionType::Prayer->value, $classified[0]['section_type']);
        $this->assertSame(30.0, $classified[0]['start_time']);
        $this->assertSame(120.0, $classified[0]['end_time']);
    }

    #[Test]
    public function it_includes_positional_context_in_the_user_prompt_when_provided(): void
    {
        // buildUserPrompt is protected — expose it via an anonymous subclass for direct testing.
        $exposed = new class extends SpeechSectionClassificationService
        {
            /** @param array<string, mixed> $serviceContext */
            public function exposePrompt(ServiceSection $section, string $transcript, array $serviceContext = []): string
            {
                $duration = max(0.0, (float) $section->end_time - (float) $section->start_time);

                return $this->buildUserPrompt($section, $duration, $transcript, $serviceContext);
            }
        };

        $section = ServiceSection::factory()->create([
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 606.0,
            'end_time' => 1469.0,
            'metadata' => [
                'transcript' => 'Good morning children.',
            ],
        ]);

        $prompt = $exposed->exposePrompt($section, 'Good morning children.', [
            'section_position' => 3,
            'section_total' => 5,
        ]);

        $this->assertStringContainsString('speech section 3 of 5', $prompt);
        $this->assertStringContainsString('606', $prompt);
        $this->assertStringContainsString('minutes long', $prompt);
    }

    #[Test]
    public function it_includes_long_segment_hint_for_sections_over_five_minutes(): void
    {
        $exposed = new class extends SpeechSectionClassificationService
        {
            /** @param array<string, mixed> $serviceContext */
            public function exposePrompt(ServiceSection $section, string $transcript, array $serviceContext = []): string
            {
                $duration = max(0.0, (float) $section->end_time - (float) $section->start_time);

                return $this->buildUserPrompt($section, $duration, $transcript, $serviceContext);
            }
        };

        $longSection = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 600.0,
            'end_time' => 1500.0, // 15 minutes
            'metadata' => ['transcript' => 'A long section.'],
        ]);

        $shortSection = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => 100.0,
            'end_time' => 220.0, // 2 minutes
            'metadata' => ['transcript' => 'A short section.'],
        ]);

        $longPrompt = $exposed->exposePrompt($longSection, 'A long section.');
        $shortPrompt = $exposed->exposePrompt($shortSection, 'A short section.');

        $this->assertStringContainsString('minutes long', $longPrompt);
        $this->assertStringNotContainsString('minutes long', $shortPrompt);
    }
}
