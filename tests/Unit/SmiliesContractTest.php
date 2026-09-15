<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\SmiliesItem;
use Aiya\Core\Api\Contract\SmiliesPack;
use PHPUnit\Framework\TestCase;

final class SmiliesContractTest extends TestCase
{
    public function testSerializesToTheContractShape(): void
    {
        $pack = new SmiliesPack('aru', [
            new SmiliesItem('滑稽', 'https://cdn.test/smilies/aru/' . rawurlencode('滑稽.webp')),
            new SmiliesItem('01', 'https://cdn.test/smilies/aru/01.png'),
        ]);

        self::assertSame([
            'slug' => 'aru',
            'items' => [
                ['code' => '滑稽', 'url' => 'https://cdn.test/smilies/aru/' . rawurlencode('滑稽.webp')],
                ['code' => '01', 'url' => 'https://cdn.test/smilies/aru/01.png'],
            ],
        ], $pack->toArray());
    }
}
