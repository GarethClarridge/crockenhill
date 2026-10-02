<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CodeRevision;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CodeRevisionTest extends TestCase
{
    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkout = storage_path('framework/testing/code-revision-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->checkout.'/app/Jobs');
        File::ensureDirectoryExists($this->checkout.'/docs/plans');
        File::put($this->checkout.'/app/Jobs/Detect.php', '<?php // detect');
        File::put($this->checkout.'/composer.lock', '{}');
        File::put($this->checkout.'/docs/plans/PLAN.md', '# Plan');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkout);

        parent::tearDown();
    }

    /** A plan edited mid-run is not new code: the batch's rounds stay valid. */
    #[Test]
    public function documentation_does_not_change_the_revision(): void
    {
        $before = CodeRevision::compute($this->checkout);

        File::put($this->checkout.'/docs/plans/PLAN.md', '# Plan, redrafted');
        File::put($this->checkout.'/README.md', 'notes');

        $this->assertSame($before, CodeRevision::compute($this->checkout));
    }

    /** Uncommitted code is code: a commit hash cannot see it, the revision does. */
    #[Test]
    public function any_change_to_code_changes_the_revision(): void
    {
        $before = CodeRevision::compute($this->checkout);

        File::put($this->checkout.'/app/Jobs/Detect.php', '<?php // detect, changed');
        $edited = CodeRevision::compute($this->checkout);
        File::put($this->checkout.'/app/Jobs/Detect.php', '<?php // detect');
        File::put($this->checkout.'/app/Jobs/Extract.php', '<?php // a new job');
        $added = CodeRevision::compute($this->checkout);

        $this->assertNotSame($before, $edited);
        $this->assertNotSame($before, $added);
        $this->assertNotSame($edited, $added);
    }

    /** A file moved to another name runs differently, so its path is part of the revision. */
    #[Test]
    public function renaming_a_file_changes_the_revision(): void
    {
        $before = CodeRevision::compute($this->checkout);

        File::move($this->checkout.'/app/Jobs/Detect.php', $this->checkout.'/app/Jobs/Detection.php');

        $this->assertNotSame($before, CodeRevision::compute($this->checkout));
    }

    #[Test]
    public function the_running_checkout_has_a_revision(): void
    {
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) CodeRevision::current());
    }
}
