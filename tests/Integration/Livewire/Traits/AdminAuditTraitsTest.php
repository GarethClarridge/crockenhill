<?php

declare(strict_types=1);

namespace Tests\Integration\Livewire\Traits;

use App\Livewire\Traits\WithAdminDelete;
use App\Livewire\Traits\WithAdminSave;
use Livewire\Component;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Pins the Phase 4 decision: admin Livewire components outside ChurchServices
 * must not log audit events via raw Log::warning() calls. All audit logging
 * must flow through WithAdminSave or WithAdminDelete so the log shape
 * (admin_id + model fields) stays consistent and queryable.
 */
class AdminAuditTraitsTest extends TestCase
{
    /**
     * Components under ChurchServices have more complex workflows (video
     * processing, email) that have not yet been migrated to the audit traits.
     */
    private const EXCLUDED_SUBDIRS = ['ChurchServices'];

    #[Test]
    public function non_church_services_admin_components_do_not_call_log_warning_directly(): void
    {
        $adminDir = app_path('Livewire/Admin');
        $finder = (new Finder)->files()->in($adminDir)->name('*.php');

        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            if ($this->isExcluded($file)) {
                continue;
            }

            $source = file_get_contents($file->getRealPath());

            if ($source !== false && str_contains($source, 'Log::warning(')) {
                $violations[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Admin Livewire components contain raw Log::warning() calls — use WithAdminSave or WithAdminDelete instead:\n - ".implode("\n - ", $violations)
        );
    }

    #[Test]
    public function admin_components_that_audit_log_use_the_audit_traits(): void
    {
        $adminDir = app_path('Livewire/Admin');
        $finder = (new Finder)->files()->in($adminDir)->name('*.php');

        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            if ($this->isExcluded($file)) {
                continue;
            }

            $class = $this->classFromFile($file);

            if ($class === null) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isTrait() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->isSubclassOf(Component::class)) {
                continue;
            }

            $traits = $this->collectTraits($reflection);
            $usesSave = in_array(WithAdminSave::class, $traits, true);
            $usesDelete = in_array(WithAdminDelete::class, $traits, true);

            if ($usesSave || $usesDelete) {
                continue;
            }

            // Any component that neither uses an audit trait nor contains Log::warning is fine.
            // A component that has Log::warning but skipped the traits is a violation.
            $source = file_get_contents($file->getRealPath());
            if ($source !== false && str_contains($source, 'Log::warning(')) {
                $violations[] = $class;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Admin Livewire components that call Log::warning() must use WithAdminSave or WithAdminDelete:\n - ".implode("\n - ", $violations)
        );
    }

    private function isExcluded(SplFileInfo $file): bool
    {
        foreach (self::EXCLUDED_SUBDIRS as $subdir) {
            if (str_contains($file->getRealPath(), DIRECTORY_SEPARATOR.$subdir.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function classFromFile(SplFileInfo $file): ?string
    {
        $relative = str_replace(
            [app_path().DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR],
            ['', '', '\\'],
            $file->getRealPath()
        );

        $class = 'App\\'.$relative;

        return class_exists($class) ? $class : null;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array<int, string>
     */
    private function collectTraits(ReflectionClass $reflection): array
    {
        $collected = [];

        $current = $reflection;
        while ($current !== false) {
            foreach ($current->getTraitNames() as $traitName) {
                $collected[] = $traitName;

                $traitReflection = new ReflectionClass($traitName);
                foreach ($traitReflection->getTraitNames() as $nested) {
                    $collected[] = $nested;
                }
            }

            $current = $current->getParentClass();
        }

        return array_values(array_unique($collected));
    }
}
