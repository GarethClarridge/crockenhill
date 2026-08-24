<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\OosEmailServicePlan;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
use App\Enums\SermonService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosEmailServicePlanTest extends TestCase
{
    #[Test]
    public function an_unrecorded_disposition_is_refused_the_evidence_tier(): void
    {
        // The decode fallback shape: `ReviewRequired` because nothing recorded a disposition, not
        // because a validator found anything. REV-D2 keeps these held.
        $plan = $this->plan(dispositionRecorded: false);

        $this->assertFalse($plan->isEvidenceImportable());
    }

    #[Test]
    public function a_recorded_disposition_reaches_the_evidence_tier(): void
    {
        $this->assertTrue($this->plan(dispositionRecorded: true)->isEvidenceImportable());
    }

    #[Test]
    public function the_evidence_tier_no_longer_depends_on_a_plan_carrying_hold_reasons(): void
    {
        // The point of the field. `isEvidenceImportable()` used to require `holdReasons !== []`,
        // which the semantic compiler satisfied only by fixing confidence at 0.75 against a 0.90
        // threshold so every plan carried `LowConfidence`. A recorded plan with an empty hold list
        // is now admitted on its own provenance, so retiring that constant cannot silently empty
        // the tier.
        $this->assertTrue($this->plan(dispositionRecorded: true, holdReasons: [])->isEvidenceImportable());
    }

    #[Test]
    public function a_missing_identity_hold_still_refuses_the_evidence_tier(): void
    {
        // The one hold reason REV-D2 does not release, unchanged by the refactor.
        $plan = $this->plan(
            dispositionRecorded: true,
            holdReasons: [OosEmailPlanHoldReason::MissingIdentity],
        );

        $this->assertFalse($plan->isEvidenceImportable());
    }

    #[Test]
    public function the_recorded_flag_survives_a_content_scope_change(): void
    {
        $plan = $this->plan(dispositionRecorded: true)->withContentScope(OosEmailContentScope::Partial);

        $this->assertTrue($plan->dispositionRecorded);
        $this->assertTrue($plan->isEvidenceImportable());
    }

    #[Test]
    public function the_recorded_flag_is_written_to_the_stored_shape(): void
    {
        $this->assertTrue($this->plan(dispositionRecorded: true)->toMetadataArray()['disposition_recorded']);
        $this->assertFalse($this->plan(dispositionRecorded: false)->toMetadataArray()['disposition_recorded']);
    }

    /** @param list<OosEmailPlanHoldReason> $holdReasons */
    private function plan(
        bool $dispositionRecorded,
        array $holdReasons = [OosEmailPlanHoldReason::LowConfidence],
    ): OosEmailServicePlan {
        return new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-19',
            items: [[
                'position' => 1,
                'type' => 'songs',
                'title' => 'In Christ Alone',
                'source_title' => null,
                'openlp_search_title' => null,
                'metadata' => null,
            ]],
            confidence: 0.75,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            holdReasons: $holdReasons,
            contentScope: OosEmailContentScope::Full,
            dispositionRecorded: $dispositionRecorded,
        );
    }
}
