<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\Notification;
use PHPUnit\Framework\TestCase;

final class NotificationContractTest extends TestCase
{
    public function testSerializesToTheCamelCaseContractShape(): void
    {
        $notification = new Notification(
            7,
            'announcement',
            '站点维护',
            '今晚 02:00 起维护一小时。',
            '2026-09-08T12:00:00+00:00'
        );

        self::assertSame(
            [
                'id' => 7,
                'type' => 'announcement',
                'title' => '站点维护',
                'body' => '今晚 02:00 起维护一小时。',
                'createdAt' => '2026-09-08T12:00:00+00:00',
            ],
            $notification->toArray()
        );
    }
}
