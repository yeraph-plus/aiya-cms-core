<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\ImageProcessor\ImagineAware;
use Imagine\Image\ImagineInterface;
use PHPUnit\Framework\TestCase;

final class ImagineAwareTest extends TestCase
{
    public function testInstanceIsPassedThroughWithoutResolving(): void
    {
        $imagine = $this->createMock(ImagineInterface::class);
        $subject = new class ($imagine) extends ImagineAware {
            public function expose(): ImagineInterface
            {
                return $this->imagine();
            }
        };

        self::assertSame($imagine, $subject->expose());
    }

    public function testClosureIsResolvedLazilyAndOnlyOnce(): void
    {
        $imagine = $this->createMock(ImagineInterface::class);
        $calls = 0;
        $subject = new class (static function () use (&$calls, $imagine): ImagineInterface {
            $calls++;

            return $imagine;
        }) extends ImagineAware {
            public function expose(): ImagineInterface
            {
                return $this->imagine();
            }
        };

        self::assertSame(0, $calls, 'Constructing must not resolve the driver.');
        self::assertSame($imagine, $subject->expose());
        self::assertSame($imagine, $subject->expose());
        self::assertSame(1, $calls, 'The resolver closure must run exactly once.');
    }
}
