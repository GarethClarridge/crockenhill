<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Enums\ChurchServiceReviewState;
use App\Models\ChurchService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_sets_manual_reviewed_by_user_id_to_null_when_user_is_deleted(): void
    {
        // 1. Create a user
        $user = User::factory()->create();

        // 2. Create a church service reviewed by that user
        $service = ChurchService::factory()->create([
            'manual_reviewed_by_user_id' => $user->id,
            'review_state' => ChurchServiceReviewState::Reviewed,
            'manual_reviewed_at' => now(),
        ]);

        $this->assertEquals($user->id, $service->manual_reviewed_by_user_id);

        // 3. Delete the user
        $user->delete();

        // 4. Refresh the service and assert the user ID is null
        $service->refresh();

        $this->assertNull($service->manual_reviewed_by_user_id);
    }
}
