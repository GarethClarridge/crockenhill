<?php

namespace Tests\Unit;

use App\Enums\PreacherSource;
use PHPUnit\Framework\TestCase;

class PreacherSourceEnumTest extends TestCase
{
    public function test_enum_has_correct_values(): void
    {
        $this->assertEquals('id3', PreacherSource::ID3->value);
        $this->assertEquals('speaker_model', PreacherSource::SPEAKER_MODEL->value);
        $this->assertEquals('manual', PreacherSource::MANUAL->value);
        $this->assertEquals('default', PreacherSource::DEFAULT->value);
    }

    public function test_label_returns_human_readable_string(): void
    {
        $this->assertEquals('ID3 Tag', PreacherSource::ID3->label());
        $this->assertEquals('Speaker Model', PreacherSource::SPEAKER_MODEL->label());
        $this->assertEquals('Manual', PreacherSource::MANUAL->label());
        $this->assertEquals('Default', PreacherSource::DEFAULT->label());
    }

    public function test_values_returns_all_values(): void
    {
        $values = PreacherSource::values();

        $this->assertContains('id3', $values);
        $this->assertContains('speaker_model', $values);
        $this->assertContains('manual', $values);
        $this->assertContains('default', $values);
        $this->assertCount(4, $values);
    }
}
