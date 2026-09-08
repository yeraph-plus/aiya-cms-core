<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Discussion\ThreadStatus;
use Aiya\Core\Domain\Discussion\ThreadType;
use PHPUnit\Framework\TestCase;

final class ThreadWorkflowTest extends TestCase
{
    public function testTypeVocabularyIsTheThreeValueContract(): void
    {
        self::assertSame(['discussion', 'question', 'feedback'], ThreadType::ALL);
        self::assertFalse(ThreadType::isValid('issue'), 'the legacy issue value is deliberately retired');
        self::assertTrue(ThreadType::isValid('question'));
    }

    public function testStatusVocabularyIsTheFourValueContract(): void
    {
        self::assertSame(['open', 'answered', 'resolved', 'closed'], ThreadStatus::ALL);
        self::assertFalse(ThreadStatus::isValid('pending'));
    }

    public function testOnlyClosedLocksReplies(): void
    {
        self::assertFalse(ThreadStatus::locksReplies(ThreadStatus::OPEN));
        self::assertFalse(ThreadStatus::locksReplies(ThreadStatus::ANSWERED));
        self::assertFalse(ThreadStatus::locksReplies(ThreadStatus::RESOLVED));
        self::assertTrue(ThreadStatus::locksReplies(ThreadStatus::CLOSED));
    }

    public function testForeignReplyFlipsOpenToAnswered(): void
    {
        self::assertSame(
            ThreadStatus::ANSWERED,
            ThreadStatus::afterReply(ThreadStatus::OPEN, 7, 5),
            'a reply from someone other than the author marks the thread answered'
        );
    }

    public function testSelfReplyKeepsTheThreadOpen(): void
    {
        self::assertSame(
            ThreadStatus::OPEN,
            ThreadStatus::afterReply(ThreadStatus::OPEN, 5, 5),
            'the author clarifying their own thread is not an answer'
        );
    }

    public function testResolvedAndAnsweredStatesAreStickyOnReply(): void
    {
        self::assertSame(ThreadStatus::RESOLVED, ThreadStatus::afterReply(ThreadStatus::RESOLVED, 7, 5));
        self::assertSame(ThreadStatus::ANSWERED, ThreadStatus::afterReply(ThreadStatus::ANSWERED, 7, 5));
        self::assertSame(ThreadStatus::CLOSED, ThreadStatus::afterReply(ThreadStatus::CLOSED, 7, 5));
    }
}
