<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Membership\RandomToken;
use PHPUnit\Framework\TestCase;

final class RandomTokenTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('wp_rand')) {
            $this->markTestSkipped('tests/bootstrap.php has no wp_rand() shim; the CSPRNG seed of RandomToken::suffix() needs it');
        }
    }

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
