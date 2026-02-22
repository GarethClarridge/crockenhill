<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ViteAssetNamingTest extends TestCase
{
    #[Test]
    public function it_uses_deterministic_asset_filenames_without_timestamp_suffixes(): void
    {
        $configPath = base_path('vite.config.mjs');
        $this->assertFileExists($configPath);

        $configContents = file_get_contents($configPath);
        $this->assertIsString($configContents);

        $this->assertStringNotContainsString('Date.now()', $configContents);
        $this->assertStringContainsString("entryFileNames: 'assets/[name]-[hash].js'", $configContents);
        $this->assertStringContainsString("chunkFileNames: 'assets/[name]-[hash].js'", $configContents);
        $this->assertStringContainsString("return 'assets/[name]-[hash].css';", $configContents);
        $this->assertStringContainsString("return 'assets/[name]-[hash][extname]';", $configContents);
    }
}
