<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Sermon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonValidationTest extends TestCase
{
    #[Test]
    public function it_validates_preacher_id_with_bigint_unsigned_capacity()
    {
        $rules = Sermon::validationRules();
        // Filter out 'exists' rule for unit testing without DB records
        $preacherRules = array_filter($rules['preacher_id'], fn ($rule) => ! is_string($rule) || ! str_contains($rule, 'exists'));

        // Standard signed 32-bit int max (was the old limit)
        $this->assertTrue(Validator::make(['preacher_id' => 2147483647], ['preacher_id' => $preacherRules])->passes());

        // Above signed 32-bit int max
        $this->assertTrue(Validator::make(['preacher_id' => 2147483648], ['preacher_id' => $preacherRules])->passes());

        // Max bigint signed (PHP limit)
        $this->assertTrue(Validator::make(['preacher_id' => '9223372036854775807'], ['preacher_id' => $preacherRules])->passes());

        // Exceeding max bigint signed
        $this->assertFalse(Validator::make(['preacher_id' => '9223372036854775808'], ['preacher_id' => $preacherRules])->passes());
    }

    #[Test]
    public function it_validates_scripture_passage_id_with_bigint_unsigned_capacity()
    {
        $rules = Sermon::validationRules();
        // Filter out 'exists' rule for unit testing without DB records
        $scriptureRules = array_filter($rules['scripture_passage_id'], fn ($rule) => ! is_string($rule) || ! str_contains($rule, 'exists'));

        // Standard signed 32-bit int max (was the old limit)
        $this->assertTrue(Validator::make(['scripture_passage_id' => 2147483647], ['scripture_passage_id' => $scriptureRules])->passes());

        // Above signed 32-bit int max
        $this->assertTrue(Validator::make(['scripture_passage_id' => 2147483648], ['scripture_passage_id' => $scriptureRules])->passes());

        // Max bigint signed (PHP limit)
        $this->assertTrue(Validator::make(['scripture_passage_id' => '9223372036854775807'], ['scripture_passage_id' => $scriptureRules])->passes());

        // Exceeding max bigint signed
        $this->assertFalse(Validator::make(['scripture_passage_id' => '9223372036854775808'], ['scripture_passage_id' => $scriptureRules])->passes());
    }

    #[Test]
    public function it_validates_download_count_with_int_unsigned_capacity()
    {
        $rules = Sermon::validationRules();

        // Standard signed 32-bit int max (was the old limit)
        $this->assertTrue(Validator::make(['download_count' => 2147483647], ['download_count' => $rules['download_count']])->passes());

        // Above signed 32-bit int max
        $this->assertTrue(Validator::make(['download_count' => 2147483648], ['download_count' => $rules['download_count']])->passes());

        // Max int unsigned
        $this->assertTrue(Validator::make(['download_count' => 4294967295], ['download_count' => $rules['download_count']])->passes());

        // Exceeding max int unsigned
        $this->assertFalse(Validator::make(['download_count' => 4294967296], ['download_count' => $rules['download_count']])->passes());
    }
}
