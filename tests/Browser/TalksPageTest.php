<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Enums\TalkType;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class TalksPageTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_a_member_switches_to_childrens_talks_on_the_same_page(): void
    {
        $user = User::factory()->create();
        Sermon::factory()->create(['title' => 'Dusk Sunday Sermon', 'content_type' => TalkType::Sermon]);
        Sermon::factory()->create(['title' => 'Dusk Little Listeners', 'content_type' => TalkType::ChildrensTalk]);

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/christ/talks')
                ->assertSee('Dusk Sunday Sermon')
                ->assertDontSee('Dusk Little Listeners')
                ->click('@talk-type-childrens_talk')
                ->waitForText('Dusk Little Listeners')
                ->assertDontSee('Dusk Sunday Sermon')
                ->assertQueryStringHas('type', 'childrens_talk');
        });
    }

    public function test_a_guest_sees_no_type_switch_while_only_sermons_are_public(): void
    {
        Sermon::factory()->create(['title' => 'Dusk Public Sermon', 'content_type' => TalkType::Sermon]);

        $this->browse(function (Browser $browser): void {
            $browser->logout()
                ->visit('/christ/talks')
                ->assertSee('Dusk Public Sermon')
                ->assertMissing('@talk-type-childrens_talk');
        });
    }

    public function test_old_urls_redirect_to_the_talks_page(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->logout()
                ->visit('/christ/sermons')
                ->waitForLocation('/christ/talks')
                ->assertPathIs('/christ/talks');
        });
    }

    public function test_each_template_renders_on_its_dated_url(): void
    {
        $user = User::factory()->create();
        $sermon = Sermon::factory()->create(['title' => 'Dusk Rich Sermon', 'content_type' => TalkType::Sermon]);
        $talk = Sermon::factory()->create(['title' => 'Dusk Simple Talk', 'content_type' => TalkType::ChildrensTalk]);

        $this->browse(function (Browser $browser) use ($user, $sermon, $talk): void {
            $browser->loginAs($user)
                ->visit('/christ/talks/'.$sermon->date->format('Y/m').'/'.$sermon->slug)
                ->assertSee('Dusk Rich Sermon')
                ->assertDontSee("Back to Children's Talks")
                ->visit('/christ/talks/'.$talk->date->format('Y/m').'/'.$talk->slug)
                ->assertSee('Dusk Simple Talk')
                ->assertSee("Back to Children's Talks");
        });
    }
}
