<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Data\HistoricSourceRootObservation;
use RuntimeException;

/**
 * An observation fake, for the facts a test host cannot produce.
 *
 * Plan §10 asks for exactly this split: unit cases inject an observation, and
 * integration cases use real temporary filesystem objects. Two things this
 * container genuinely cannot do are mount a second failure domain on demand and
 * make a directory unwritable to root, so those two facts are supplied here
 * while the inventory half — links, dispositions, hard links, byte sets —
 * stays real.
 *
 * It is deliberately not a general-purpose stub: every root must be described
 * up front, so a case cannot pass by accident against a root nobody thought
 * about.
 */
final class FakeHistoricSourceFilesystemInspector implements HistoricSourceFilesystemInspector
{
    /** @var array<string, HistoricSourceRootObservation> */
    private array $roots = [];

    /** @var array<string, array<string, string>> */
    private array $xattrs = [];

    /** @var callable|null */
    private $onFirstXattrRead;

    /** @var callable|null */
    private $onFirstObservation;

    private bool $mutated = false;

    private bool $observed = false;

    public function __construct(
        private readonly bool $supportsExtendedAttributes = false,
        private readonly ?string $xattrReadFailure = null,
    ) {}

    /**
     * Run once, part-way through the first inventory pass — the only place a
     * test can move a tree while it is being read.
     */
    public function mutateDuringFirstInventory(callable $mutation): self
    {
        $this->onFirstXattrRead = $mutation;

        return $this;
    }

    /**
     * Run once inside the first root observation.
     *
     * HIR5's artifact resolver observes the mount between its two reads of an
     * artifact, so this is the only place a test can change a file while it is
     * being verified.
     */
    public function mutateDuringFirstObservation(callable $mutation): self
    {
        $this->onFirstObservation = $mutation;

        return $this;
    }

    /** @param list<string> $mountOptions */
    public function root(
        string $root,
        string $failureDomainSeed,
        bool $readOnly = true,
        bool $writeProbeFailed = true,
        array $mountOptions = ['ro'],
    ): self {
        $canonical = realpath($root);

        $this->roots[$canonical === false ? $root : $canonical] = new HistoricSourceRootObservation(
            root: $root,
            canonicalPath: $canonical === false ? $root : $canonical,
            platform: 'Linux',
            inspectorVersion: 'fake-observation-v1',
            deviceIdentity: 'device-'.$failureDomainSeed,
            mountPoint: '/mnt/'.$failureDomainSeed,
            mountSource: '/dev/'.$failureDomainSeed,
            filesystemType: 'ext4',
            mountOptions: $mountOptions,
            readOnly: $readOnly,
            writeProbeFailed: $writeProbeFailed,
        );

        return $this;
    }

    /** @param array<string, string> $attributes */
    public function xattrsFor(string $path, array $attributes): self
    {
        $this->xattrs[$path] = $attributes;

        return $this;
    }

    public function platform(): string
    {
        return 'Linux';
    }

    public function observeRoot(string $root): HistoricSourceRootObservation
    {
        $canonical = realpath($root);
        $key = $canonical === false ? $root : $canonical;

        if (! isset($this->roots[$key])) {
            throw new RuntimeException("No observation was prepared for historic source root {$root}.");
        }

        if ($this->onFirstObservation !== null && ! $this->observed) {
            $this->observed = true;
            ($this->onFirstObservation)();
        }

        return $this->roots[$key];
    }

    public function supportsExtendedAttributes(): bool
    {
        return $this->supportsExtendedAttributes;
    }

    public function xattrs(string $path): array
    {
        if ($this->xattrReadFailure !== null && str_ends_with($path, $this->xattrReadFailure)) {
            throw new RuntimeException("Historic source extended attributes cannot be read: {$path}");
        }

        if ($this->onFirstXattrRead !== null && ! $this->mutated) {
            $this->mutated = true;
            ($this->onFirstXattrRead)();
        }

        return $this->xattrs[$path] ?? [];
    }
}
