<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TalkType;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The archive moved from /christ/sermons to /christ/talks and Children's
 * Corner folded into it; every old URL keeps answering with a 301.
 */
class LegacyTalkRedirectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_old_archive_root_redirects_permanently(): void
    {
        $this->get('/christ/sermons')
            ->assertStatus(301)
            ->assertRedirect('/christ/talks');
    }

    #[Test]
    public function old_archive_paths_redirect_with_their_query_string(): void
    {
        $this->get('/christ/sermons/2026/02/some-sermon')
            ->assertStatus(301)
            ->assertRedirect('/christ/talks/2026/02/some-sermon');

        $this->get('/christ/sermons?book=Romans&chapter=8')
            ->assertStatus(301)
            ->assertRedirect('/christ/talks?book=Romans&chapter=8');
    }

    #[Test]
    public function old_podcast_feeds_redirect_to_their_new_path(): void
    {
        $this->get('/christ/sermons/morning/feed')
            ->assertStatus(301)
            ->assertRedirect('/christ/talks/morning/feed');
    }

    #[Test]
    public function childrens_corner_redirects_to_the_childrens_talks_view(): void
    {
        $this->get('/christ/childrens-corner')
            ->assertStatus(301)
            ->assertRedirect(route('sermons.index', ['type' => 'childrens_talk']));
    }

    #[Test]
    public function a_childrens_corner_talk_redirects_to_its_dated_url(): void
    {
        $talk = Sermon::factory()->create([
            'slug' => 'a-talk-for-children',
            'date' => '2026-02-01',
            'content_type' => TalkType::ChildrensTalk,
        ]);

        $this->get('/christ/childrens-corner/a-talk-for-children')
            ->assertStatus(301)
            ->assertRedirect(route('sermons.show.dated', ['year' => '2026', 'month' => '02', 'sermon' => $talk->slug]));
    }

    #[Test]
    public function a_sermon_slug_is_not_found_under_childrens_corner(): void
    {
        Sermon::factory()->create(['slug' => 'regular-sermon', 'content_type' => TalkType::Sermon]);

        $this->get('/christ/childrens-corner/regular-sermon')->assertNotFound();
    }

    #[Test]
    public function an_unknown_childrens_corner_slug_is_not_found(): void
    {
        $this->get('/christ/childrens-corner/no-such-talk')->assertNotFound();
    }
}
