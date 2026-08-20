<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticParserOutcome;
use App\Services\Email\OosSemanticParserCandidate;
use Closure;
use RuntimeException;

/**
 * A parser candidate that returns a stated extraction instead of annotating and compiling one.
 *
 * Tests of the *pipeline around* the parser — the archive command, the weekly job, the reparse and
 * approve actions — need a deterministic parse, not a real one. Before Delivery 7 they got that by
 * binding a fake `OosEmailItemExtractor`, which is gone with the legacy path. They inject at the same
 * level here: {@see OosSemanticParserCandidate::parse()} is the seam that returns a compiled
 * extraction, so overriding it substitutes the whole annotate-validate-compile stage in one place.
 *
 * This is deliberately *not* a fake annotator. A fake annotator would make each of these tests
 * author a line-by-line annotation map for its own fixture body and would put the real compiler in
 * the path, so a compiler change could fail forty tests that are not about the compiler. Tests that
 * mean to exercise annotation and compilation bind {@see FakeOosSemanticAnnotator}
 * and let the real candidate run.
 */
class FixedOosSemanticParserCandidate extends OosSemanticParserCandidate
{
    /**
     * How many times the pipeline actually parsed.
     *
     * Kept because several callers assert it — idempotence and cache-reuse tests turn on whether a
     * second run reparsed — and the fakes this replaces carried their own counter.
     */
    public int $calls = 0;

    /**
     * The received date of each source parsed, in order.
     *
     * Recorded because the archive tests assert on it: the synthetic received date is part of the
     * raw cache key, so a run that reparsed under a different date is a cache defect rather than a
     * duplicate call.
     *
     * @var list<?string>
     */
    public array $receivedDates = [];

    /** @var Closure(OosEmailSourceDocument): OosEmailItemExtractionResult */
    private Closure $resolver;

    /** @param Closure(OosEmailSourceDocument): OosEmailItemExtractionResult $resolver */
    private function __construct(Closure $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Always return this extraction, whatever the source.
     */
    public static function returning(OosEmailItemExtractionResult $extraction): self
    {
        return new self(static fn (OosEmailSourceDocument $source): OosEmailItemExtractionResult => $extraction);
    }

    /**
     * Decide the extraction from the source, for tests whose fixture varies by subject or date.
     *
     * @param  Closure(OosEmailSourceDocument): OosEmailItemExtractionResult  $resolver
     */
    public static function using(Closure $resolver): self
    {
        return new self($resolver);
    }

    /**
     * Fail if the parser is reached at all.
     *
     * Used where the point of the test is that a stored parse was reused, or that a gate refused
     * before parsing — a case that a candidate returning an empty result would silently pass.
     */
    public static function unreachable(string $message = 'The parser must not be reached.'): self
    {
        return new self(static function (OosEmailSourceDocument $source) use ($message): never {
            throw new RuntimeException($message);
        });
    }

    /**
     * Change the answer for subsequent parses.
     *
     * The reparse tests need one source to be read two different ways — a second run whose date or
     * confidence moved — which is what `--fresh-parse` exists to exercise. The fakes this replaces
     * did it by reassigning a public property between runs.
     */
    public function willReturn(OosEmailItemExtractionResult $extraction): void
    {
        $this->resolver = static fn (OosEmailSourceDocument $source): OosEmailItemExtractionResult => $extraction;
    }

    public function parse(OosEmailSourceDocument $source): OosSemanticParserOutcome
    {
        $this->calls++;
        $this->receivedDates[] = $source->receivedDate;

        return new OosSemanticParserOutcome(($this->resolver)($source), [[]], []);
    }
}
