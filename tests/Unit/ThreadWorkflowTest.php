<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Discussion\ThreadStatus;
use PHPUnit\Framework\TestCase;

final class ThreadWorkflowTest extends TestCase
{
    public function testStatusVocabularyIsTheTwoValueContract(): void
    {
        self::assertSame(['open', 'closed'], ThreadStatus::ALL);
        self::assertFalse(ThreadStatus::isValid('answered'), 'issue-style values were retired with the boards rework');
        self::assertFalse(ThreadStatus::isValid('resolved'));
        self::assertFalse(ThreadStatus::isValid('pending'));
    }

    public function testOnlyClosedLocksReplies(): void
    {
        self::assertFalse(ThreadStatus::locksReplies(ThreadStatus::OPEN));
        self::assertTrue(ThreadStatus::locksReplies(ThreadStatus::CLOSED));
    }
}
