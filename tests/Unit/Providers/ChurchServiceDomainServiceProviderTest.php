<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Events\ChurchServiceCanonicalListChanged;
use App\Listeners\DispatchChurchServiceReconciliation;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceDomainServiceProviderTest extends TestCase
{
    #[Test]
    public function church_service_canonical_list_changed_event_has_dispatch_reconciliation_listener_registered(): void
    {
        $this->assertTrue(
            Event::hasListeners(ChurchServiceCanonicalListChanged::class),
            'ChurchServiceCanonicalListChanged must have at least one listener registered.'
        );

        $this->assertContains(
            DispatchChurchServiceReconciliation::class,
            Event::getRawListeners()[ChurchServiceCanonicalListChanged::class] ?? [],
        );
    }
}
