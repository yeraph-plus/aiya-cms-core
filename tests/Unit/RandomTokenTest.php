<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Payment\RandomToken;
use PHPUnit\Framework\TestCase;

final class RandomTokenTest extends TestCase
{
    protected function setUp(): void
    {    }

    public function testSuffixIsUppercaseAlphanumericAtTheRequestedLength(): void
    {
        foreach ([1, 8, 32] as $length) {
            self::assertMatchesRegularExpression('/^[0-9A-Z]{' . $length . '}$/', RandomToken::suffix($length));
        }
    }

    public function testSuffixLengthClampsIntoTheOneToThirtyTwoWindow(): void
    {
        self::assertSame(1, strlen(RandomToken::suffix(0)));
        self::assertSame(32, strlen(RandomToken::suffix(100)));
    }

    public function testFiftySuffixesInARowStayUnique(): void
    {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = RandomToken::suffix(32);
        }

        self::assertCount(50, array_unique($tokens));
    }
}
