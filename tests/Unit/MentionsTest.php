<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Mention\Mentions;
use PHPUnit\Framework\TestCase;
use WP_User;

/**
 * The @mention service: token resolution (exact nicename first, then an
 * exact-and-unique display name — ambiguity resolves to nobody), the
 * notification list (first-appearance order, deduplicated, capped), and
 * the reference-anchor injection (zero-routing markers, no href). The
 * scan only sees text nodes: fenced code, existing anchor interiors,
 * comments and attribute values never gain — or corrupt — a mention.
 */
final class MentionsTest extends TestCase
{
    private Mentions $mentions;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $GLOBALS['__aiya_test_users'] = [];
        $this->mentions = new Mentions();
    }

    /** Registers a user for the `get_user_by` shim; `$inDb` also mirrors
     * the display name into the wpdb stand-in for the display-name leg. */
    private function user(int $id, string $nicename, string $display, bool $inDb = false): void
    {
        $GLOBALS['__aiya_test_users'][$id] = new WP_User((object) [
            'ID' => $id,
            'user_nicename' => $nicename,
            'display_name' => $display,
        ]);
        if ($inDb) {
            global $wpdb;
            $wpdb->aiya_test_rows['wp_users'][] = ['ID' => $id, 'display_name' => $display];
        }
    }

    // ------------------------------------------------------------- resolve

    public function testExactNicenameResolvesTheUser(): void
    {
        $this->user(5, 'alice-nm', 'Alice');

        self::assertSame([5], $this->mentions->resolve('<p>看 @alice-nm 一下</p>'));
    }

    public function testAUniqueDisplayNameResolvesThroughTheDatabaseLeg(): void
    {
        $this->user(7, 'uuid-7', '小明', inDb: true);

        self::assertSame([7], $this->mentions->resolve('@小明 帮忙看看'));
    }

    public function testAnAmbiguousDisplayNameResolvesToNobody(): void
    {
        $this->user(7, 'uuid-7', '小明', inDb: true);
        $this->user(8, 'uuid-8', '小明', inDb: true);

        self::assertSame([], $this->mentions->resolve('叫一下 @小明'), 'two hits mean nobody is mentioned');
        self::assertSame([7], $this->mentions->resolve('@uuid-7 在吗'), 'the nicename leg is untouched by the ambiguity');
    }

    public function testUnknownTokensResolveToNothing(): void
    {
        $this->user(5, 'alice-nm', 'Alice');

        self::assertSame([], $this->mentions->resolve('@nobody-here 和 @也不是'));
    }

    public function testCodeAndPreInteriorsNeverMention(): void
    {
        $this->user(5, 'alice-nm', 'Alice');
        $this->user(6, 'bob-uuid', 'Bob');

        self::assertSame([], $this->mentions->resolve('<code>@alice-nm</code><pre>@bob-uuid</pre>'));
        self::assertSame(
            [5],
            $this->mentions->resolve('<p>@alice-nm</p><code>@alice-nm</code>'),
            'the same token stays a mention where it is plain text'
        );
    }

    public function testAnchorInteriorsNeverMention(): void
    {
        $this->user(5, 'alice-nm', 'Alice');
        $this->user(6, 'bob-uuid', 'Bob');

        self::assertSame([], $this->mentions->resolve('<a href="https://x.test/u/alice-nm">@alice-nm</a>'));
        self::assertSame(
            [6],
            $this->mentions->resolve('<a href="https://x.test/u/alice-nm">@alice-nm</a> 顺便叫 @bob-uuid')
        );
    }

    public function testResolveDedupsKeepsFirstAppearanceAndCaps(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->user($i, 'u' . $i, 'U' . $i);
        }

        self::assertSame(
            [2, 1],
            $this->mentions->resolve('叫 @u2 和 @u1，再叫一次 @u2'),
            'first appearance wins over token order and duplicates collapse'
        );
        self::assertSame(
            range(1, Mentions::MAX_PER_CONTENT),
            $this->mentions->resolve('@u1 @u2 @u3 @u4 @u5 @u6 @u7 @u8 @u9 @u10 @u11 @u12'),
            'the cap holds even when every token resolves'
        );
    }

    public function testResolveHonoursTheExclusionList(): void
    {
        $this->user(5, 'alice-nm', 'Alice');
        $this->user(6, 'bob-uuid', 'Bob');

        self::assertSame([6], $this->mentions->resolve('@alice-nm 和 @bob-uuid', [5]));
        self::assertSame([5, 6], $this->mentions->resolve('@alice-nm 和 @bob-uuid', [99]));
    }

    // ------------------------------------------------------------- linkify

    public function testLinkifyInjectsReferenceAnchorsPerToken(): void
    {
        $this->user(5, 'alice-nm', 'Alice');
        $this->user(6, 'bob-uuid', 'Bob');

        self::assertSame(
            '看 <a data-aiya-ref="user" data-aiya-nicename="alice-nm">@Alice</a>'
            . ' 和 <a data-aiya-ref="user" data-aiya-nicename="bob-uuid">@Bob</a> 一下',
            $this->mentions->linkify('看 @alice-nm 和 @bob-uuid 一下'),
            'both tokens of one text node resolve and carry the display name'
        );
    }

    public function testLinkifyKeepsUnresolvableTokensPlainText(): void
    {
        self::assertSame(
            '@nobody-here 和 @nor-this 都不对应任何账户',
            $this->mentions->linkify('@nobody-here 和 @nor-this 都不对应任何账户')
        );
    }

    public function testLinkifySkipsFencesEvenWhenTheTokenIsMentionedElsewhere(): void
    {
        $this->user(5, 'alice-nm', 'Alice');

        self::assertSame(
            '<p><a data-aiya-ref="user" data-aiya-nicename="alice-nm">@Alice</a></p><code>@alice-nm</code>',
            $this->mentions->linkify('<p>@alice-nm</p><code>@alice-nm</code>'),
            'the fenced copy must never grow an anchor'
        );
    }

    public function testLinkifyLeavesAttributeValuesAlone(): void
    {
        $this->user(5, 'alice-nm', 'Alice');

        self::assertSame(
            '<img src="/x.png" alt="看看 @alice-nm"><a data-aiya-ref="user" data-aiya-nicename="alice-nm">@Alice</a>',
            $this->mentions->linkify('<img src="/x.png" alt="看看 @alice-nm">@alice-nm')
        );
    }

    public function testLinkifyNeverNestsInsideAnExistingAnchor(): void
    {
        $this->user(6, 'bob-uuid', 'Bob');

        self::assertSame(
            '<a href="https://x.test/u/bob-uuid">@bob-uuid</a> 顺便叫 '
            . '<a data-aiya-ref="user" data-aiya-nicename="bob-uuid">@Bob</a>',
            $this->mentions->linkify('<a href="https://x.test/u/bob-uuid">@bob-uuid</a> 顺便叫 @bob-uuid'),
            'the existing anchor keeps its label; the plain-text copy becomes the reference'
        );
    }

    public function testLinkifyHonoursTheExclusionList(): void
    {
        $this->user(5, 'alice-nm', 'Alice');
        $this->user(6, 'bob-uuid', 'Bob');

        self::assertSame(
            '@alice-nm 和 <a data-aiya-ref="user" data-aiya-nicename="bob-uuid">@Bob</a>',
            $this->mentions->linkify('@alice-nm 和 @bob-uuid', [5])
        );
    }

    public function testLinkifyReturnsTheInputUntouchedWithoutTokens(): void
    {
        self::assertSame('<p>没有提及的一段话。</p>', $this->mentions->linkify('<p>没有提及的一段话。</p>'));
    }
}
