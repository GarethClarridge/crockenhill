<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\PageArea;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingPublicAccessScopeTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_includes_meetings_with_no_linked_page(): void
    {
        /** @var Meeting $meeting */
        $meeting = Meeting::factory()->create(['page_id' => null]);

        $results = Meeting::publiclyAccessible()->get();

        $this->assertTrue($results->contains($meeting));
    }

    #[Test]
    public function it_includes_meetings_with_linked_public_pages(): void
    {
        /** @var Page $page */
        $page = Page::factory()->create([
            'admin' => 'no',
            'area' => PageArea::Church->value,
        ]);
        /** @var Meeting $meeting */
        $meeting = Meeting::factory()->create(['page_id' => $page->id]);

        $results = Meeting::publiclyAccessible()->get();

        $this->assertTrue($results->contains($meeting));
    }

    #[Test]
    public function it_excludes_meetings_linked_to_admin_only_pages(): void
    {
        /** @var Page $page */
        $page = Page::factory()->admin()->create();
        /** @var Meeting $meeting */
        $meeting = Meeting::factory()->create(['page_id' => $page->id]);

        $results = Meeting::publiclyAccessible()->get();

        $this->assertFalse($results->contains($meeting));
    }

    #[Test]
    public function it_excludes_meetings_linked_to_members_area_pages(): void
    {
        /** @var Page $page */
        $page = Page::factory()->inArea(PageArea::Members)->create(['admin' => 'no']);
        /** @var Meeting $meeting */
        $meeting = Meeting::factory()->create(['page_id' => $page->id]);

        $results = Meeting::publiclyAccessible()->get();

        $this->assertFalse($results->contains($meeting));
    }

    #[Test]
    public function it_filters_correctly_across_multiple_meetings(): void
    {
        // Included
        /** @var Meeting $public1 */
        $public1 = Meeting::factory()->create(['page_id' => null, 'slug' => 'public-1']);
        /** @var Page $public2Page */
        $public2Page = Page::factory()->create(['admin' => 'no', 'area' => PageArea::Community->value]);
        /** @var Meeting $public2 */
        $public2 = Meeting::factory()->create(['page_id' => $public2Page->id, 'slug' => 'public-2']);

        // Excluded
        /** @var Page $adminPage */
        $adminPage = Page::factory()->admin()->create();
        /** @var Meeting $excludedAdmin */
        $excludedAdmin = Meeting::factory()->create(['page_id' => $adminPage->id, 'slug' => 'excluded-admin']);
        /** @var Page $membersPage */
        $membersPage = Page::factory()->inArea(PageArea::Members)->create(['admin' => 'no']);
        /** @var Meeting $excludedMembers */
        $excludedMembers = Meeting::factory()->create(['page_id' => $membersPage->id, 'slug' => 'excluded-members']);

        $results = Meeting::publiclyAccessible()->get();

        $this->assertTrue($results->contains($public1));
        $this->assertTrue($results->contains($public2));
        $this->assertFalse($results->contains($excludedAdmin));
        $this->assertFalse($results->contains($excludedMembers));
    }
}
