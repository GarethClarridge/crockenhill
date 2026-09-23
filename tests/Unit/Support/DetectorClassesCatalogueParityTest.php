<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\DetectorStatus;
use App\Support\DetectorCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Binds the discovered defect classes to {@see DetectorCatalogue}.
 *
 * H1 asks that a defect class's `detector_id` cannot drift from the code. The
 * classes live in `resources/detector-classes.json`, a data file, so a class
 * naming an id nobody implemented fails here. They were parsed out of the
 * historic plan's §4.3a table until 2026-09-23; a test reading a plan tied the
 * build to prose that is meant to be condensed and archived.
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
class DetectorClassesCatalogueParityTest extends TestCase
{
    private const CLASSES = 'resources/detector-classes.json';

    public function test_every_detector_id_in_the_plan_exists_in_the_catalogue(): void
    {
        $catalogued = array_keys(DetectorCatalogue::all());

        foreach ($this->classIds() as $row => $id) {
            $this->assertContains(
                $id,
                $catalogued,
                "Defect class {$row} names detector_id [{$id}], which the catalogue does not carry."
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
    public function test_every_class_carries_a_detector_id(): void
    {
        $ids = $this->classIds();

        $this->assertNotSame([], $ids, 'The defect class file is empty.');

        foreach ($ids as $row => $id) {
            $this->assertNotSame('', trim($id), "Class {$row} has an empty detector_id.");
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
        foreach (array_unique($this->classIds()) as $id) {
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
        foreach (array_unique($this->classIds()) as $id) {
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
     * The defect classes' ids, keyed by their 1-indexed position in the file.
     *
     * @return array<int, string>
     */
    private function classIds(): array
    {
        $path = dirname(__DIR__, 3).'/'.self::CLASSES;
        $this->assertFileExists($path, 'The defect class file has moved or been renamed.');

        $classes = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $ids = [];

        foreach ($classes as $index => $class) {
            $ids[$index + 1] = (string) ($class['detector_id'] ?? '');
        }

        return $ids;
    }
}
