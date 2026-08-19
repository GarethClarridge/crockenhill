<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Enums\OosSemanticItemKind;
use App\Services\Email\OosSemanticAnnotationDecoder;
use App\Services\Email\OosSemanticAnnotationPrompt;
use App\Services\Email\OosSemanticAnnotationSchema;
use App\Services\Email\OpenAiOosSemanticAnnotator;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiOosSemanticAnnotatorTest extends TestCase
{
    #[Test]
    public function it_uses_strict_source_keyed_output_and_never_asks_the_model_to_copy_titles(): void
    {
        config()->set('openai.api_key', 'test-key');
        config()->set('service-tracking.email_parsing.semantic.transport_attempts', 1);
        OpenAI::fake([CreateResponse::fake([
            'model' => 'gpt-5.6-terra-2026-08-01',
            'choices' => [['message' => ['content' => json_encode([
                'services' => [[
                    'group_id' => 'morning',
                    'proposed_service' => 'morning',
                    'boundary_line_ids' => [1],
                    'uncertainties' => [],
                ]],
                'annotations' => [
                    'L001' => $this->annotation('service_boundary', 'morning'),
                    'L003' => $this->annotation('item', 'morning', 'song'),
                ],
            ])]]],
        ])]);
        $schema = new OosSemanticAnnotationSchema;
        $annotator = new OpenAiOosSemanticAnnotator(
            OpenAI::getFacadeRoot(),
            $schema,
            new OosSemanticAnnotationDecoder($schema),
            new OosSemanticAnnotationPrompt,
        );

        $result = $annotator->annotate(OosEmailSourceDocument::fromContext(
            'Sunday order',
            "Morning\n\nAmazing Grace",
            '2026-08-19',
        ));

        $this->assertSame(OosSemanticItemKind::Song, $result->annotations[3]->itemKind);
        $this->assertSame('gpt-5.6-terra-2026-08-01', $result->telemetry['returned_model']);
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $format = $parameters['response_format']['json_schema'];
            $encodedSchema = json_encode($format['schema']);

            return $method === 'create'
                && $format['strict'] === true
                && $format['schema']['properties']['annotations']['required'] === ['L001', 'L003']
                && $format['schema']['properties']['annotations']['additionalProperties'] === false
                && ! str_contains((string) $encodedSchema, 'title')
                && str_contains($parameters['messages'][1]['content'], '[L003');
        });
    }

    #[Test]
    public function it_retries_a_truncated_transport_response_without_retrying_semantic_validation(): void
    {
        config()->set('openai.api_key', 'test-key');
        config()->set('service-tracking.email_parsing.semantic.transport_attempts', 2);
        config()->set('service-tracking.email_parsing.semantic.retry_delays_ms', [0, 0]);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['finish_reason' => 'length', 'message' => ['content' => '{']]],
            ]),
            CreateResponse::fake([
                'choices' => [['message' => ['content' => json_encode([
                    'services' => [[
                        'group_id' => 'morning',
                        'proposed_service' => 'morning',
                        'boundary_line_ids' => [1],
                        'uncertainties' => [],
                    ]],
                    'annotations' => [
                        'L001' => $this->annotation('service_boundary', 'morning'),
                    ],
                ])]]],
            ]),
        ]);
        $schema = new OosSemanticAnnotationSchema;
        $annotator = new OpenAiOosSemanticAnnotator(
            OpenAI::getFacadeRoot(),
            $schema,
            new OosSemanticAnnotationDecoder($schema),
            new OosSemanticAnnotationPrompt,
        );

        $result = $annotator->annotate(OosEmailSourceDocument::fromContext(
            'Sunday order',
            'Morning',
            '2026-08-19',
        ));

        $this->assertSame('morning', $result->services[0]->groupId);
        OpenAI::assertSent(Chat::class, 2);
    }

    /** @return array<string, mixed> */
    private function annotation(string $role, ?string $group, ?string $kind = null): array
    {
        return [
            'role' => $role,
            'service_group_id' => $group,
            'item_kind' => $kind,
            'continuation_target_line_id' => null,
            'uncertainty' => null,
            'shared_service_group_ids' => [],
            'boundary_also_item' => false,
        ];
    }
}
