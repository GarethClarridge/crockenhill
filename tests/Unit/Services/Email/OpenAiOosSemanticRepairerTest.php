<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosCandidateService;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticFinding;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Services\Email\OosSemanticAnnotationDecoder;
use App\Services\Email\OosSemanticAnnotationSchema;
use App\Services\Email\OpenAiOosSemanticRepairer;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiOosSemanticRepairerTest extends TestCase
{
    #[Test]
    public function it_retains_returned_model_usage_and_latency_with_the_patch(): void
    {
        config()->set('openai.api_key', 'test-key');
        config()->set('service-tracking.email_parsing.semantic.transport_attempts', 1);
        OpenAI::fake([CreateResponse::fake([
            'model' => 'gpt-5.6-terra-2026-08-01',
            'choices' => [['message' => ['content' => json_encode([
                'annotations' => [
                    'L002' => $this->annotation('item', 'morning', 'song'),
                ],
            ])]]],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 30,
                'total_tokens' => 150,
            ],
        ])]);
        $source = OosEmailSourceDocument::fromContext(
            'Sunday order',
            "Morning service\nAmazing Grace",
            '2026-08-19',
        );
        $annotations = new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::OtherContext, 'morning', OosSemanticItemKind::Song, null, null),
            ],
        );
        $schema = new OosSemanticAnnotationSchema;
        $repairer = new OpenAiOosSemanticRepairer(
            OpenAI::getFacadeRoot(),
            $schema,
            new OosSemanticAnnotationDecoder($schema),
        );

        $patch = $repairer->repair($source, $annotations, [
            new OosSemanticFinding('non_item_has_kind', 'Item kind requires item role.', [2], ['role']),
        ]);

        $this->assertSame(OosSemanticRole::Item, $patch->replacements[2]->role);
        $this->assertSame('gpt-5.6-terra-2026-08-01', $patch->telemetry['returned_model']);
        $this->assertSame(1, $patch->telemetry['attempt']);
        $this->assertSame(150, $patch->telemetry['usage']['total_tokens']);
        $this->assertIsInt($patch->telemetry['latency_ms']);
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
