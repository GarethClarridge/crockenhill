<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Data\OosEmailSourceDocument;
use App\Exceptions\OpenAiSchemaLimitException;
use App\Services\Email\OosSemanticAnnotationSchema;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiJsonSchemaLimits;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiJsonSchemaLimitsTest extends TestCase
{
    #[Test]
    public function it_counts_enum_values_across_the_whole_schema_and_definitions_once(): void
    {
        $counts = OpenAiJsonSchemaLimits::measure([
            'type' => 'object',
            '$defs' => [
                'slot' => ['type' => 'string', 'enum' => ['morning', 'evening']],
            ],
            'properties' => [
                'first' => ['$ref' => '#/$defs/slot'],
                'second' => ['$ref' => '#/$defs/slot'],
                'kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', 'b', 'c']]],
            ],
        ]);

        $this->assertSame(5, $counts['enum_values']);
        $this->assertSame(3, $counts['properties']);
    }

    #[Test]
    public function it_refuses_a_request_whose_schema_exceeds_the_enum_budget(): void
    {
        $this->expectException(OpenAiSchemaLimitException::class);
        $this->expectExceptionMessageMatches('/enum values \(limit 1000\)/');

        OpenAiChatPayload::forModel([
            'model' => 'gpt-5-nano',
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'oos_semantic_annotations',
                    'strict' => true,
                    'schema' => (new OosSemanticAnnotationSchema)->build($this->sourceOfLines(400)),
                ],
            ],
        ]);
    }

    #[Test]
    public function it_leaves_a_payload_without_a_schema_alone(): void
    {
        $payload = OpenAiChatPayload::forModel(['model' => 'gpt-4o', 'max_tokens' => 50]);

        $this->assertSame(50, $payload['max_completion_tokens']);
    }

    private function sourceOfLines(int $count): OosEmailSourceDocument
    {
        $lines = [];

        for ($line = 1; $line <= $count; $line++) {
            $lines[] = "Item {$line}";
        }

        return OosEmailSourceDocument::fromBody(implode("\n", $lines));
    }
}
