<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\OpenAiServiceStructureService;
use App\Services\ChurchService\Structure\ServiceStructureEvaluationTelemetry;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OpenAiServiceStructureServiceTest extends TestCase
{
    private OpenAiServiceStructureService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.analysis.openai_api_key', 'test-key');
        Config::set('media-processing.service_structure.model', 'gpt-5.6-sol');
        Config::set('media-processing.service_structure.reasoning_effort', 'medium');
        Config::set('openai.service_tier', 'flex');

        $this->service = new OpenAiServiceStructureService;
    }

    #[Test]
    public function it_detects_a_structure_from_a_valid_response(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'sections' => [
                                [
                                    'type' => 'welcome',
                                    'title' => 'Welcome',
                                    'start_time' => 0.0,
                                    'end_time' => 60.0,
                                    'confidence' => 0.95,
                                    'oos_item_id' => 1,
                                    'song_title' => null,
                                    'reading_reference' => null,
                                    'sermon_reference' => null,
                                    'summary' => 'The service opens with a welcome to the congregation.',
                                    'notes' => [],
                                ],
                                [
                                    'type' => 'sermon',
                                    'title' => 'The faithfulness of God',
                                    'start_time' => 430.0,
                                    'end_time' => 2200.0,
                                    'confidence' => 0.97,
                                    'oos_item_id' => 4,
                                    'song_title' => null,
                                    'reading_reference' => null,
                                    'sermon_reference' => 'Joshua 1:1-9',
                                    'summary' => 'The sermon explains God’s faithfulness from Joshua chapter one.',
                                    'notes' => ['Single expository block.'],
                                ],
                            ],
                            'summary' => 'The service welcomes the congregation and teaches from Joshua chapter one.',
                            'notices' => [[
                                'title' => 'Holiday club',
                                'details' => 'Registration opens next week.',
                            ]],
                            'chapter_markers' => [[
                                'title' => 'Welcome',
                                'start_time' => 0.0,
                                'end_time' => 60.0,
                            ], [
                                'title' => 'Sermon',
                                'start_time' => 430.0,
                                'end_time' => 2200.0,
                            ]],
                            'notes' => ['Clean detection.'],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $structure = $this->service->detect($this->transcript(), $this->oosItems(), 'proc-1');

        $this->assertCount(2, $structure->sections);
        $this->assertSame(ServiceSectionType::Welcome, $structure->sections[0]->type);
        $this->assertSame(ServiceSectionType::Sermon, $structure->sections[1]->type);
        $this->assertSame(4, $structure->sections[1]->oosItemId);
        $this->assertSame('The sermon explains God’s faithfulness from Joshua chapter one.', $structure->sections[1]->summary);
        $this->assertSame('The service welcomes the congregation and teaches from Joshua chapter one.', $structure->summary);
        $this->assertSame([
            ['title' => 'Holiday club', 'details' => 'Registration opens next week.'],
        ], $structure->notices);
        $this->assertCount(2, $structure->chapterMarkers);
        $this->assertSame(['Clean detection.'], $structure->notes);
        $this->assertSame('gpt-5.6-sol', $structure->model);
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            return $method === 'create'
                && $parameters['model'] === 'gpt-5.6-sol'
                && $parameters['service_tier'] === 'flex'
                && $parameters['reasoning_effort'] === 'medium'
                && ! array_key_exists('temperature', $parameters);
        });
    }

    #[Test]
    public function it_records_usage_into_an_injected_evaluation_telemetry(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'sections' => [[
                                'type' => 'welcome',
                                'title' => 'Welcome',
                                'start_time' => 0.0,
                                'end_time' => 60.0,
                                'confidence' => 0.95,
                                'oos_item_id' => 1,
                                'song_title' => null,
                                'reading_reference' => null,
                                'sermon_reference' => null,
                                'summary' => null,
                                'notes' => [],
                            ]],
                            'summary' => null,
                            'notices' => [],
                            'chapter_markers' => [],
                            'notes' => [],
                        ]),
                    ],
                ]],
                'usage' => [
                    'prompt_tokens' => 3000,
                    'completion_tokens' => 400,
                    'total_tokens' => 3400,
                ],
            ]),
        ]);

        $telemetry = new ServiceStructureEvaluationTelemetry;
        $service = new OpenAiServiceStructureService($telemetry);

        $service->detect($this->transcript(), $this->oosItems());

        $this->assertSame([
            'input_tokens' => 3000,
            'cached_input_tokens' => 0,
            'output_tokens' => 400,
            'reasoning_tokens' => 0,
            'total_tokens' => 3400,
        ], $telemetry->take());
    }

    #[Test]
    public function it_throws_on_malformed_json(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => 'this is not JSON {'],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode');

        $this->service->detect($this->transcript(), $this->oosItems());
    }

    #[Test]
    public function it_throws_when_sections_are_missing(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['notes' => []])],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not include sections');

        $this->service->detect($this->transcript(), $this->oosItems());
    }

    #[Test]
    public function it_throws_on_an_empty_response(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => ''],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty response');

        $this->service->detect($this->transcript(), $this->oosItems());
    }

    #[Test]
    public function it_normalises_an_unknown_section_type_to_other_with_a_review_flag(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'sections' => [[
                                'type' => 'liturgical_response',
                                'title' => null,
                                'start_time' => 0.0,
                                'end_time' => 90.0,
                                'confidence' => 0.7,
                                'oos_item_id' => null,
                                'song_title' => null,
                                'reading_reference' => null,
                                'notes' => [],
                            ]],
                            'notes' => [],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $structure = $this->service->detect($this->transcript(), $this->oosItems());

        $this->assertSame(ServiceSectionType::Other, $structure->sections[0]->type);
        $this->assertContains(
            ServiceStructureSection::REVIEW_FLAG_UNKNOWN_TYPE,
            $structure->sections[0]->reviewFlags
        );
    }

    #[Test]
    public function it_throws_when_no_usable_sections_remain(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'sections' => [['type' => 'sermon', 'start_time' => 'later', 'end_time' => 'eventually']],
                            'notes' => [],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no usable sections');

        $this->service->detect($this->transcript(), $this->oosItems());
    }

    #[Test]
    public function it_rejects_a_missing_api_key(): void
    {
        Config::set('media-processing.analysis.openai_api_key', null);
        Config::set('openai.api_key', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API key');

        $this->service->detect($this->transcript(), $this->oosItems());
    }

    #[Test]
    public function it_rejects_an_empty_transcript(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty transcript');

        $this->service->detect(
            ChurchServiceTranscript::fromCues([], 0.0, ChurchServiceTranscript::SOURCE_MOCK),
            $this->oosItems()
        );
    }

    #[Test]
    public function the_prompt_carries_oos_ids_cue_timestamps_and_the_invariant_rules(): void
    {
        $prompt = $this->service->buildPrompt($this->transcript(), $this->oosItems());

        // The user message must ground the model in the OoS ids and cue timings.
        $this->assertStringContainsString('id 1 | position 1 | type welcome', $prompt['user']);
        $this->assertStringContainsString('id 4 | position 4 | type sermon | title "The faithfulness of God"', $prompt['user']);
        $this->assertStringContainsString('[0:00-0:30] Good morning everyone and a warm welcome.', $prompt['user']);
        $this->assertStringContainsString('[7:10-7:40] Please turn with me to Joshua chapter one.', $prompt['user']);
        $this->assertStringContainsString('Recording duration: 2400 seconds', $prompt['user']);

        // The system message must state every structural invariant the
        // deterministic gate depends on.
        $this->assertStringContainsString('Do NOT invent sections', $prompt['system']);
        $this->assertStringContainsString('AT MOST ONCE', $prompt['system']);
        $this->assertStringContainsString('Items of the SAME type (e.g. two songs) must be claimed', $prompt['system']);
        $this->assertStringContainsString('DIFFERENT types may legitimately be performed in a different', $prompt['system']);
        $this->assertStringContainsString('shorter than 15 seconds', $prompt['system']);
        $this->assertStringContainsString('exactly ONE primary sermon', $prompt['system']);
        $this->assertStringContainsString('ONE section', $prompt['system']);
        $this->assertStringContainsString('childrens_talk ONLY with structural cues', $prompt['system']);
        $this->assertStringContainsString('object lesson, a catechism question', $prompt['system']);
        $this->assertStringContainsString('do NOT label it prayer or other merely because', $prompt['system']);
        $this->assertStringContainsString('that prayer belongs INSIDE the sermon section', $prompt['system']);
        $this->assertStringContainsString('sermon_reference', $prompt['system']);
        $this->assertStringContainsString('summary: a faithful one-sentence summary', $prompt['system']);
        $this->assertStringContainsString('notices contains one entry per distinct announcement', $prompt['system']);
        $this->assertStringContainsString('chapter_markers are the significant, listener-friendly sections', $prompt['system']);
        $this->assertStringContainsString('MUST come from the supplied cue', $prompt['system']);
        $this->assertStringContainsString('British English', $prompt['system']);
    }

    #[Test]
    public function the_prompt_states_when_no_oos_is_available(): void
    {
        $prompt = $this->service->buildPrompt($this->transcript(), []);

        $this->assertStringContainsString('No order of service is available', $prompt['user']);
        $this->assertStringContainsString('every oos_item_id must be null', $prompt['user']);
    }

    private function transcript(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 30.0, 'text' => 'Good morning everyone and a warm welcome.'],
            ['start' => 430.0, 'end' => 460.0, 'text' => 'Please turn with me to Joshua chapter one.'],
        ], 2400.0, ChurchServiceTranscript::SOURCE_MOCK);
    }

    /**
     * @return array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>
     */
    private function oosItems(): array
    {
        return [
            ['id' => 1, 'position' => 1, 'type' => 'welcome', 'title' => 'Welcome', 'song_id' => null],
            ['id' => 2, 'position' => 2, 'type' => 'song', 'title' => 'Praise My Soul', 'song_id' => 9],
            ['id' => 3, 'position' => 3, 'type' => 'bible_reading', 'title' => 'Joshua 1:1-9', 'song_id' => null],
            ['id' => 4, 'position' => 4, 'type' => 'sermon', 'title' => 'The faithfulness of God', 'song_id' => null],
        ];
    }
}
