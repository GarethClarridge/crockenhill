<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Models\Sermon;
use App\Presenters\SermonMetaPresenter;
use App\Presenters\SermonViewPresenter;
use App\Services\Sermon\SermonExposurePolicy;
use Carbon\Carbon;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonMetaPresenterTest extends TestCase
{
    private SermonExposurePolicy&MockInterface $exposurePolicy;

    private SermonViewPresenter&MockInterface $presenter;

    private SermonMetaPresenter $metaPresenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exposurePolicy = Mockery::mock(SermonExposurePolicy::class);
        $this->presenter = Mockery::mock(SermonViewPresenter::class);
        $this->metaPresenter = new SermonMetaPresenter($this->exposurePolicy);
    }

    #[Test]
    public function it_builds_sermon_image_alt_text_with_preacher(): void
    {
        $sermon = Sermon::factory()->make(['title' => 'Grace Abounding']);
        $this->presenter->shouldReceive('displayPreacherName')->with($sermon)->andReturn('John Smith');

        $this->assertSame(
            'Sermon: Grace Abounding by John Smith',
            $this->metaPresenter->imageAlt($this->presenter, $sermon),
        );
    }

    #[Test]
    public function it_builds_sermon_image_alt_text_without_preacher(): void
    {
        $sermon = Sermon::factory()->make(['title' => 'Grace Abounding']);
        $this->presenter->shouldReceive('displayPreacherName')->with($sermon)->andReturn(null);

        $this->assertSame(
            'Sermon: Grace Abounding',
            $this->metaPresenter->imageAlt($this->presenter, $sermon),
        );
    }

    #[Test]
    public function it_builds_childrens_talk_image_alt_text(): void
    {
        $sermon = Sermon::factory()->make(['title' => 'The Lost Sheep']);
        $this->presenter->shouldReceive('displayPreacherName')->with($sermon)->andReturn('Jane Doe');

        $this->assertSame(
            "Children's Corner: The Lost Sheep by Jane Doe",
            $this->metaPresenter->childrensTalkImageAlt($this->presenter, $sermon),
        );
    }

    #[Test]
    public function it_prefers_the_stored_meta_description(): void
    {
        $sermon = Sermon::factory()->make(['meta_description' => 'A hand-written description.']);

        $this->assertSame(
            'A hand-written description.',
            $this->metaPresenter->metaDescription($this->presenter, $sermon),
        );
    }

    #[Test]
    public function it_assembles_a_meta_description_from_resolved_facts(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'Test Sermon',
            'meta_description' => null,
            'date' => Carbon::parse('2024-03-10'),
            'series' => null,
            'show_summary' => true,
            'summary' => 'This is a summary.',
        ]);

        $this->presenter->shouldReceive('displayPreacherName')->with($sermon)->andReturn('John Smith');
        $this->presenter->shouldReceive('humanDate')->with($sermon)->andReturn('March 10, 2024');
        $this->presenter->shouldReceive('displayReference')->with($sermon)->andReturn('John 3:16');
        $this->presenter->shouldReceive('serviceLabel')->with($sermon)->andReturn('Morning');
        $this->exposurePolicy->shouldReceive('shouldExposeVideo')->with($sermon)->andReturn(false);

        $description = $this->metaPresenter->metaDescription($this->presenter, $sermon);

        $this->assertStringContainsString('Test Sermon', $description);
        $this->assertStringContainsString('John Smith', $description);
        $this->assertStringContainsString('March 10, 2024', $description);
        $this->assertStringContainsString('John 3:16', $description);
        $this->assertStringContainsString('This is a summary.', $description);
    }
}
