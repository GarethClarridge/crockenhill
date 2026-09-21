<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\DetectorStatus;
use App\Support\DetectorCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Binds §4.3a's class table to {@see DetectorCatalogue}.
 *
 * H1 asks for a test that stops the plan's `detector_id` column being prose
 * that drifts from the code. This is it: the column is parsed out of the plan
 * file itself, so a row naming an id nobody implemented fails here rather than
 * being discovered when a report comes back with a gap in it.
 *
 * **One-directional, and deliberately so.** H1 originally specified set
 * equality. Measured on 2026-09-21 that turned out to be the wrong contract,
 * because the two artefacts are indexed differently: the table is a record of
 * defect classes *as discovered*, the catalogue an inventory of detectors *as
 * emitted*, and those are many-to-many. Twenty-one promoted detectors — the
 * micro-section and low-confidence flags, the four order-of-service anchoring
 * flags, several song publication objections — exist in the pipeline because
 * they were built before the censuses ran, and no §4.1 class produced them.
 * Requiring equality would mean inventing table rows describing no found
 * defect, which would corrupt the discovery record to satisfy a test.
 *
 * The reverse direction is already covered from the code side, and better:
 * {@see DetectorCatalogueTest} walks each emitting class's own constants and
 * globs the `Flag*` actions, so a detector cannot ship uncatalogued. What that
 * cannot check, and this can, is the plan naming something that does not exist.
 */
class DetectorCataloguePlanParityTest extends TestCase
{
    private const PLAN = 'docs/plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md';

    public function test_every_detector_id_in_the_plan_exists_in_the_catalogue(): void
    {
        $catalogued = array_keys(DetectorCatalogue::all());

        foreach ($this->planIds() as $row => $id) {
            $this->assertContains(
                $id,
                $catalogued,
                "§4.3a class table row {$row} names detector_id [{$id}], which the catalogue does not carry."
            );
        }
    }

    /**
     * Every class-table row carries an id.
     *
     * A blank cell is the state H1 exists to abolish: it makes a class with no
     * detector indistinguishable from one nobody has ruled on, which is exactly
     * what {@see DetectorStatus} was made an enum to prevent.
     */
    public function test_the_class_table_is_fully_populated(): void
    {
        $ids = $this->planIds();

        $this->assertCount(
            54,
            $ids,
            'Every row of §4.3a\'s class table needs a detector_id. A new row must bring one.'
        );

        foreach ($ids as $row => $id) {
            $this->assertNotSame('', trim($id), "Row {$row} has an empty detector_id cell.");
        }
    }

    /**
     * Every class the plan found is accounted for by a status, not by silence.
     *
     * The point of §4.3a is that a class has a tested response *or* a recorded
     * decision. An entry that is neither promoted, prototyped, fixed nor ruled
     * on is the open state, and that is fine — what is not fine is a class whose
     * status nobody has set at all, which this asserts cannot happen because the
     * enum has no null.
     */
    public function test_every_named_class_resolves_to_a_status(): void
    {
        foreach (array_unique($this->planIds()) as $id) {
            $entry = DetectorCatalogue::find($id);

            $this->assertNotNull($entry, "[{$id}] is named by the plan but absent from the catalogue.");
            $this->assertInstanceOf(DetectorStatus::class, $entry->status);
        }
    }

    /**
     * A class with no detector must say why, unless it is simply still open.
     *
     * This is the assertion that keeps the column honest. Without it, marking a
     * row `FixedAtSource` would be a free way to make an unguarded class look
     * handled.
     */
    public function test_classes_without_detectors_record_their_grounds(): void
    {
        foreach (array_unique($this->planIds()) as $id) {
            $entry = DetectorCatalogue::find($id);
            $this->assertNotNull($entry);

            if (! $entry->status->requiresDecision()) {
                continue;
            }

            $this->assertNotNull($entry->decision, "[{$id}] is {$entry->status->value} but records no grounds.");
            $this->assertNotSame('', trim((string) $entry->decision));
        }
    }

    /**
     * The class table's ids, keyed by their 1-indexed row number.
     *
     * @return array<int, string>
     */
    private function planIds(): array
    {
        $path = dirname(__DIR__, 3).'/'.self::PLAN;
        $this->assertFileExists($path, 'The plan this test binds to has moved or been renamed.');

        $lines = explode("\n", (string) file_get_contents($path));
        $ids = [];
        $inTable = false;
        $row = 0;

        foreach ($lines as $line) {
            if (str_starts_with($line, '| Class | `detector_id` |')) {
                $inTable = true;

                continue;
            }

            if (! $inTable) {
                continue;
            }

            if (str_starts_with($line, '|---')) {
                continue;
            }

            if (! str_starts_with($line, '|')) {
                break;
            }

            $cells = array_map(trim(...), explode('|', $line));

            // [0] is the empty string before the leading pipe, [1] the class,
            // [2] the id.
            $ids[++$row] = trim($cells[2] ?? '', '` ');
        }

        $this->assertTrue($inTable, 'Could not find §4.3a\'s class table header in the plan.');

        return $ids;
    }
}
