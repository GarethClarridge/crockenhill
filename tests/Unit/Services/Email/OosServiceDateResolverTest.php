<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Services\Email\OosServiceDateResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosServiceDateResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_the_sunday_on_or_after_a_weekday_received_date_with_no_stated_date(): void
    {
        // Monday. The commonest corpus form: no date anywhere, just "order of service for
        // Sunday" naming the day of the week without saying which one.
        $source = OosEmailSourceDocument::fromContext(
            'Order of service for Sunday',
            "Morning Service\nHymn: How Great Thou Art",
            '2026-08-17',
        );

        $this->assertSame('2026-08-23', $this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function it_resolves_to_the_received_date_itself_when_already_a_sunday(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Details for Sun',
            'Morning Service',
            '2026-08-23',
        );

        $this->assertSame('2026-08-23', $this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function a_source_stated_date_wins_over_the_fallback_even_when_it_contradicts_the_received_date(): void
    {
        // The received/authority date is a Sunday in July, but the body states a June date.
        // The explicit-date rung fires first and the fallback is never reached.
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            'Sunday 5th June 2026',
            '2026-07-05',
        );

        $this->assertSame('2026-06-05', $this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function christmas_morning_resolves_to_christmas_day_in_the_context_year(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            'Christmas morning service',
            '2023-12-25',
        );

        $this->assertSame('2023-12-25', $this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function it_resolves_sunday_ordinals_within_the_context_month(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            "Sunday morning (20th)\nSunday 27th (morning only)",
            '2015-12-15',
        );

        $this->assertSame('2015-12-20', $this->resolver()->resolve($source, [1]));
        $this->assertSame('2015-12-27', $this->resolver()->resolve($source, [2]));
    }

    #[Test]
    public function the_fallback_is_suppressed_when_only_the_subject_names_a_special_service(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Carol service details',
            'Order of service',
            '2023-12-25',
        );

        $this->assertNull($this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function a_special_service_token_outside_the_evidence_lines_does_not_suppress_the_fallback(): void
    {
        // Line 2 mentions Christmas but is not among the supplied evidence line IDs, so it must
        // not be read as evidence for this service's own boundary.
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            "Morning Service\nRemember next week's Christmas service",
            '2026-08-17',
        );

        $this->assertSame('2026-08-23', $this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function it_still_returns_null_with_no_received_date_and_no_stated_date(): void
    {
        $source = OosEmailSourceDocument::fromBody('Morning Service');

        $this->assertNull($this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function an_impossible_explicit_day_and_month_resolves_to_null_rather_than_a_normalised_date(): void
    {
        // Carbon normalises 31 February to 3 March rather than rejecting it. Resolving that would
        // hand the weekly evidence path a plausible but wrong service identity, which no manifest
        // is there to contradict, so the impossible date must be refused outright.
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            'Service on 31st February 2018',
            '2018-02-14',
        );

        $this->assertNull($this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function an_impossible_explicit_numeric_date_resolves_to_null_rather_than_a_normalised_date(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            'Service on 31/2/2018',
            '2018-02-14',
        );

        $this->assertNull($this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function an_impossible_iso_date_resolves_to_null_rather_than_a_normalised_date(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            'Service on 2018-02-31',
            '2018-02-14',
        );

        $this->assertNull($this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function a_yearless_month_name_before_the_received_month_rolls_forward_to_next_year(): void
    {
        // Sent 2022-12-31 for "1st January" with no year stated. contextYear() previously
        // defaulted to the received date's own year (2022), landing the plan a full year early
        // (2022-01-01, a Saturday) instead of the intended New Year's Day service (2023-01-01, a
        // Sunday) — the December-to-January boundary these emails routinely cross.
        $source = OosEmailSourceDocument::fromContext(
            'Hymns for 1st January am',
            'Here are the hymns/songs for tomorrow morning:',
            '2022-12-31',
        );

        $this->assertSame('2023-01-01', $this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function a_yearless_month_name_on_or_after_the_received_month_stays_in_the_received_year(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            'Service on 10th October',
            '2024-10-03',
        );

        $this->assertSame('2024-10-10', $this->resolver()->resolve($source, [1]));
    }

    #[Test]
    public function a_real_end_of_month_date_still_resolves(): void
    {
        // The guard must refuse only dates that do not exist. A genuine 29 February in a leap year
        // is the case most likely to be broken by an over-eager overflow check.
        $source = OosEmailSourceDocument::fromContext(
            'Order of service',
            'Service on 29th February 2020',
            '2020-02-14',
        );

        $this->assertSame('2020-02-29', $this->resolver()->resolve($source, [1]));
    }

    private function resolver(): OosServiceDateResolver
    {
        return new OosServiceDateResolver;
    }
}
