<?php

declare(strict_types=1);

namespace Tests\Integration\Livewire\Traits;

use App\Livewire\Admin\ChurchServices\Concerns\ManagesSectionPublication;
use App\Livewire\Traits\WithAdminAuthorization;
use Livewire\Component;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Pins the Option B (safety net) decision from the April 2026 review:
 * every Livewire component under App\Livewire\Admin MUST use the
 * WithAdminAuthorization trait, even though route middleware also enforces
 * admin access. This guards against the route group being silently
 * misconfigured in the future.
 */
class AdminLivewireComponentsUseTraitTest extends TestCase
{
    #[Test]
    public function every_admin_livewire_component_uses_the_admin_authorization_trait(): void
    {
        $adminDir = app_path('Livewire/Admin');
        $finder = (new Finder)->files()->in($adminDir)->name('*.php');

        $missing = [];

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
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

            if (! $this->usesTraitRecursive($reflection, WithAdminAuthorization::class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Admin Livewire components missing WithAdminAuthorization trait:\n - ".implode("\n - ", $missing)
        );
    }

    #[Test]
    public function every_section_publication_action_authorizes_before_working(): void
    {
        $source = file_get_contents((new ReflectionClass(ManagesSectionPublication::class))->getFileName());

        self::assertIsString($source);
        self::assertStringNotContainsString('method_exists($this, \'authorizeAdmin\')', $source);

        foreach (['approve', 'reject', 'requeue'] as $action) {
            self::assertMatchesRegularExpression(
                '/public function '.$action.'\b.*?\{\s*\$this->authorizeAdmin\(\);/s',
                $source,
                "The {$action} section publication action must authorize before mutating state."
            );
        }
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
     */
    private function usesTraitRecursive(ReflectionClass $reflection, string $trait): bool
    {
        $traits = $this->collectTraits($reflection);

        return in_array($trait, $traits, true);
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
