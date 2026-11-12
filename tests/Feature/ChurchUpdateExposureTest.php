<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TalkType;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Livewire\Sermons\BrowseSermons;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use App\Seo\SermonItemListPresenter;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchUpdateExposureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        config(['church.talks.public_types' => TalkType::values(), 'podcast.cache.enabled' => false]);
    }

    #[Test]
    public function the_admin_editor_names_a_church_update_without_treating_it_as_a_family_talk(): void
    {
        $talk = $this->churchUpdate();
        Livewire::actingAs(User::factory()->create(['is_admin' => true]))
            ->test(EditSermon::class, ['sermon' => $talk])
            ->assertSee('Edit Church Update')->assertSee('Church update notes')
            ->assertSee('Update the published church update details.')
            ->assertDontSee("Children's Corner");
    }

    #[Test]
    public function guests_and_unverified_users_cannot_open_church_updates_or_their_assets(): void
    {
        $talk = $this->churchUpdate();
        foreach ([null, User::factory()->create(['email_verified_at' => null])] as $user) {
            if ($user !== null) {
                $this->actingAs($user);
            }
            foreach ([$this->datedUrl($talk), '/christ/talks?type=church_update', ...$this->assetUrls($talk)] as $url) {
                $this->get($url)->assertRedirect(route('login'));
            }
        }
    }

    #[Test]
    public function verified_members_can_browse_and_open_church_updates_with_noindex(): void
    {
        $talk = $this->churchUpdate();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

        foreach ([$this->datedUrl($talk), '/christ/talks?type=church_update'] as $url) {
            $this->get($url)->assertOk()->assertSee($talk->title)
                ->assertSee('<meta name="robots" content="noindex">', false);
        }
    }

    #[Test]
    public function public_pages_and_search_never_advertise_church_updates(): void
    {
        $talk = $this->churchUpdate();
        $preacher = Preacher::factory()->create(['name' => 'Public listing preacher']);
        $talk->update(['series' => 'Shared archive series', 'preacher_id' => $preacher->id]);
        $sermon = Sermon::factory()->create(['title' => 'Public listing control', 'series' => $talk->series, 'preacher_id' => $preacher->id]);

        foreach (['/', '/christ/talks', '/christ/talks/all', '/christ/talks/morning', '/christ/talks/evening', '/christ/talks/other', '/christ/talks/series', '/christ/talks/series/shared-archive-series', '/christ/talks/preachers', route('sermons.preacher', $preacher)] as $url) {
            $this->followingRedirects()->get($url)->assertOk()->assertDontSee($talk->title)->assertDontSee($talk->slug);
        }
        $this->get('/christ/talks')->assertSee($sermon->title);
        $this->getJson('/api/sermons?search='.urlencode($talk->title))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/sermons?search='.urlencode($sermon->title))
            ->assertOk()->assertJsonFragment(['id' => $sermon->id]);
    }

    #[Test]
    public function a_guest_cannot_select_church_updates_through_a_livewire_request(): void
    {
        $talk = $this->churchUpdate();

        Livewire::test(BrowseSermons::class)
            ->call('selectType', TalkType::ChurchUpdate->value)
            ->assertSet('typeFilter', TalkType::Sermon->value)
            ->assertDontSee($talk->title)
            ->assertDontSee('Church Updates');
    }

    #[Test]
    public function verified_members_can_open_each_church_update_asset(): void
    {
        $talk = $this->churchUpdate();
        $talk->update([
            'thumbnail_file_path' => 'thumbnails/church-update.webp',
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/church-update-plain.webp', 'card_thumbnail_path' => 'thumbnails/church-update-card.webp'],
            'transcript_file_path' => 'transcripts/church-update.txt',
        ]);
        config(['media-processing.storage.transcript_disk' => 'public']);
        foreach ([$talk->audio_file_path, $talk->video_file_path, $talk->thumbnail_file_path, $talk->plain_thumbnail_file_path, $talk->card_thumbnail_file_path, $talk->transcript_file_path] as $path) {
            Storage::disk('public')->put($path, 'Church update asset');
        }
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

        foreach (array_slice($this->assetUrls($talk), 0, 5) as $url) {
            $response = $this->get($url)->assertRedirect();
            $this->assertNotSame(route('login'), $response->headers->get('Location'));
        }
        $this->get(route('sermons.transcript', $talk))->assertOk()->assertSee('Church update asset');
    }

    #[Test]
    public function private_family_talks_emit_no_article_or_media_structured_data(): void
    {
        config(['church.talks.public_types' => ['sermon']]);
        $talk = Sermon::factory()->withAudio()->create(['content_type' => TalkType::ChildrensTalk]);
        $this->actingAs(User::factory()->create());

        $this->get($this->datedUrl($talk))->assertOk()->assertSee('Family Talk')
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertDontSee('"@type": "Article"', false)
            ->assertDontSee('"@type": "AudioObject"', false)
            ->assertDontSee('"contentUrl"', false);
    }

    #[Test]
    public function the_admin_family_talk_copy_uses_the_reviewed_label(): void
    {
        $talk = Sermon::factory()->create(['content_type' => TalkType::ChildrensTalk]);
        Livewire::actingAs(User::factory()->create(['is_admin' => true]))
            ->test(EditSermon::class, ['sermon' => $talk])
            ->assertSee('Update the published family talk details.')
            ->assertDontSee("children's talk");

        Livewire::test(ListSermons::class)->assertSee('published talks')->assertDontSee("children's talks");
    }

    #[Test]
    public function public_api_list_and_detail_never_return_a_church_update_even_to_a_member(): void
    {
        $talk = $this->churchUpdate();
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/sermons')->assertOk()->assertJsonMissing(['id' => $talk->id]);
        $this->getJson('/api/sermons/'.$talk->id)->assertNotFound();
        $this->assertFalse(app(SermonExposurePolicy::class)->shouldExposeOnSermonApi($talk));
    }

    #[Test]
    public function podcast_feeds_never_include_church_updates(): void
    {
        $talk = $this->churchUpdate();
        $sermon = Sermon::factory()->withAudio()->create(['title' => 'Public feed control', 'service' => 'morning']);
        Storage::disk('public')->put($sermon->audio_file_path, 'audio');

        foreach (['morning', 'evening'] as $service) {
            $response = $this->get('/christ/talks/'.$service.'/feed')->assertOk();
            $response->assertDontSee($talk->title)->assertDontSee($talk->slug)->assertDontSee($talk->audio_file_path);
            if ($service === 'morning') {
                $response->assertSee($sermon->title);
            }
        }
    }

    #[Test]
    public function church_updates_emit_no_article_or_media_structured_data_even_for_members(): void
    {
        $talk = $this->churchUpdate();
        $this->actingAs(User::factory()->create());
        foreach ([$this->datedUrl($talk), '/christ/talks?type=church_update'] as $url) {
            $response = $this->get($url)->assertOk();
            foreach (['Article', 'AudioObject', 'VideoObject'] as $type) {
                $response->assertDontSee('"@type": "'.$type.'"', false);
            }
            $response->assertDontSee('"contentUrl"', false);
        }

        $schema = app(SermonItemListPresenter::class)->toItemList(collect([$talk]));
        $this->assertSame(0, $schema['numberOfItems']);
        $this->assertSame([], $schema['itemListElement']);
    }

    #[Test]
    public function church_updates_never_appear_on_public_service_records_or_in_the_sitemap_query(): void
    {
        $talk = $this->churchUpdate();
        $policy = app(SermonExposurePolicy::class);
        $this->assertFalse($policy->shouldExposeOnChurchService($talk));
        $this->assertFalse($policy->shouldIncludeInSitemap($talk));
        $this->assertFalse(Sermon::query()->whereVisibleInSitemap()->whereKey($talk->id)->exists());
    }

    private function churchUpdate(): Sermon
    {
        return Sermon::factory()->withAudio()->create([
            'content_type' => TalkType::ChurchUpdate,
            'title' => 'Private conference report',
            'slug' => 'private-conference-report',
            'service' => 'morning',
            'video_file_path' => 'sermons/private-conference-report.mp4',
        ]);
    }

    private function datedUrl(Sermon $talk): string
    {
        return '/christ/talks/'.$talk->date->format('Y/m').'/'.$talk->slug;
    }

    /** @return list<string> */
    private function assetUrls(Sermon $talk): array
    {
        return array_map(fn (string $name): string => route('sermons.'.$name, $talk), [
            'audio', 'video', 'thumbnail', 'thumbnail.plain', 'thumbnail.card', 'transcript',
        ]);
    }
}
