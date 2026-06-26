<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\SermonScriptureFilter;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonScriptureFilterIntegrityTest extends TestCase
{
    private const UNSIGNED_INTEGER_MAX = 4294967295;

    private const UNSIGNED_SMALL_INTEGER_MAX = 65535;

    #[Test]
    public function validation_rules_mirror_database_column_types(): void
    {
        $rules = SermonScriptureFilter::validationRules();

        // sermon_id: unsignedInteger (max: 4294967295)
        $this->assertContains('max:'.self::UNSIGNED_INTEGER_MAX, $rules['sermon_id']);
        $this->assertValidationPasses($rules['sermon_id'], 'sermon_id', self::UNSIGNED_INTEGER_MAX);
        $this->assertValidationFails($rules['sermon_id'], 'sermon_id', self::UNSIGNED_INTEGER_MAX + 1);

        // bible_chapter: unsignedSmallInteger (max: 65535)
        $this->assertContains('max:'.self::UNSIGNED_SMALL_INTEGER_MAX, $rules['bible_chapter']);
        $this->assertValidationPasses($rules['bible_chapter'], 'bible_chapter', self::UNSIGNED_SMALL_INTEGER_MAX);
        $this->assertValidationFails($rules['bible_chapter'], 'bible_chapter', self::UNSIGNED_SMALL_INTEGER_MAX + 1);

        // bible_book: varchar(50)
        $this->assertContains('max:50', $rules['bible_book']);
        $this->assertValidationPasses($rules['bible_book'], 'bible_book', str_repeat('a', 50));
        $this->assertValidationFails($rules['bible_book'], 'bible_book', str_repeat('a', 51));
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function assertValidationPasses(array $rules, string $field, mixed $value): void
    {
        $validator = Validator::make([$field => $value], [$field => $this->rulesWithoutExists($rules)]);

        $this->assertTrue($validator->passes(), $validator->errors()->first($field));
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function assertValidationFails(array $rules, string $field, mixed $value): void
    {
        $validator = Validator::make([$field => $value], [$field => $this->rulesWithoutExists($rules)]);

        $this->assertTrue($validator->fails());
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private function rulesWithoutExists(array $rules): array
    {
        return array_values(array_filter(
            $rules,
            static fn (mixed $rule): bool => ! is_string($rule) || ! str_starts_with($rule, 'exists:')
        ));
    }
}
