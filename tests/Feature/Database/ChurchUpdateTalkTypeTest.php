<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\TalkType;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchUpdateTalkTypeTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function model_validation_accepts_the_named_type_but_no_catch_all(): void
    {
        $rules = ['content_type' => Sermon::validationRules()['content_type']];
        $this->assertTrue(Validator::make(['content_type' => 'church_update'], $rules)->passes());
        $this->assertFalse(Validator::make(['content_type' => 'none'], $rules)->passes());
        $this->assertFalse(Validator::make(['content_type' => 'other'], $rules)->passes());
    }

    #[Test]
    public function every_named_type_round_trips_through_the_content_type_column(): void
    {
        foreach (TalkType::cases() as $type) {
            $sermon = Sermon::factory()->create(['content_type' => $type]);
            $this->assertSame($type, $sermon->fresh()->content_type);
        }
    }

    #[Test]
    public function the_widened_mysql_column_keeps_its_default_nullability_and_collation(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL enum constraint.');
        }
        $column = DB::table('information_schema.columns')
            ->whereRaw('table_schema = database()')->where('table_name', 'sermons')->where('column_name', 'content_type')->first();

        $this->assertNotNull($column);
        $this->assertSame("enum('sermon','childrens_talk','partner_update','testimony','church_update')", $column->COLUMN_TYPE);
        $this->assertSame('sermon', $column->COLUMN_DEFAULT);
        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertSame('utf8mb3_unicode_ci', $column->COLLATION_NAME);
    }
}
