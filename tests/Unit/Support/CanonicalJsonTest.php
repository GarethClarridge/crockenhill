<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalJsonTest extends TestCase
{
    #[Test]
    public function associative_key_order_does_not_change_the_encoding_or_hash(): void
    {
        $left = [
            'service' => [
                'notices' => [
                    ['details' => 'After the service', 'title' => 'Lunch'],
                ],
                'summary' => 'Morning worship',
            ],
            'items' => [
                ['title' => 'First', 'position' => 1],
                ['title' => 'Second', 'position' => 2],
            ],
        ];
        $right = [
            'items' => [
                ['position' => 1, 'title' => 'First'],
                ['position' => 2, 'title' => 'Second'],
            ],
            'service' => [
                'summary' => 'Morning worship',
                'notices' => [
                    ['title' => 'Lunch', 'details' => 'After the service'],
                ],
            ],
        ];

        $this->assertSame(CanonicalJson::encode($left), CanonicalJson::encode($right));
        $this->assertSame(CanonicalJson::hash($left), CanonicalJson::hash($right));
        $this->assertMatchesRegularExpression('/\\A[a-f0-9]{64}\\z/', CanonicalJson::hash($left));
    }

    #[Test]
    public function list_order_remains_significant(): void
    {
        $left = [['position' => 1], ['position' => 2]];
        $right = [['position' => 2], ['position' => 1]];

        $this->assertNotSame(CanonicalJson::hash($left), CanonicalJson::hash($right));
    }
}
