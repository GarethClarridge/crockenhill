<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

abstract class RuledEndingsShardTestCase extends RuledEndingsTestCase
{
    protected const SHARD = 0;

    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return RuledEndingsShards::fixtures(static::SHARD);
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function a_captured_runs_edges_keep_their_rulings(string $path): void
    {
        $capture = json_decode((string) gzdecode((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        $this->checkCapture($capture);
    }
}
