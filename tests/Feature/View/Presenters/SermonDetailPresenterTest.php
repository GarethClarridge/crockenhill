<?php

declare(strict_types=1);

namespace Tests\Feature\View\Presenters;

use App\Models\Sermon;
use App\View\Presenters\PageLinksRepository;
use App\View\Presenters\SermonDetailPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonDetailPresenterTest extends TestCase
{
    use DatabaseTransactions;

    private SermonDetailPresenter $presenter;

    private PageLinksRepository $linksRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linksRepository = $this->createMock(PageLinksRepository::class);
        $this->presenter = new SermonDetailPresenter($this->linksRepository);
    }

    #[Test]
    public function it_presents_sermon_data_when_sermon_exists(): void
    {
        $date = now();
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'date' => $date,
        ]);

        $url = sprintf('/christ/sermons/%s/%s/test-sermon', $date->year, $date->format('m'));
        $request = Request::create($url);

        $this->linksRepository->expects($this->once())
            ->method('orderedLinks')
            ->with('sermons', 'test-sermon', 'christ', false, ['homepage'])
            ->willReturn(new Collection);

        $view = View::make('layouts/page');

        $result = $this->presenter->present($request, $view);

        $this->assertEquals($sermon->meta_description, $result['description']);
        $this->assertEquals('Test Sermon', $result['heading']);
        $this->assertEquals('/images/headings/large/test-sermon.webp', $result['headingpicture']);
        $this->assertEquals('christ', $result['area']);
        $this->assertEquals('test-sermon', $result['slug']);
        $this->assertInstanceOf(Collection::class, $result['links']);
    }

    #[Test]
    public function it_presents_empty_data_when_sermon_is_not_found(): void
    {
        $request = Request::create('/christ/sermons/2024/01/non-existent');
        $view = View::make('layouts/page');

        $result = $this->presenter->present($request, $view);

        $this->assertEquals('', $result['description']);
        $this->assertEquals('', $result['heading']);
        $this->assertEquals('', $result['headingpicture']);
        $this->assertEquals('christ', $result['area']);
        $this->assertEquals('non-existent', $result['slug']);
        $this->assertInstanceOf(Collection::class, $result['links']);
        $this->assertCount(0, $result['links']);
    }
}
