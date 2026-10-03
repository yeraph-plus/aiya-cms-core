<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\Mentions;
use Aiya\Core\Domain\Mail\MailTemplate;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Identity\FavoriteService;
use Aiya\Core\Domain\Identity\FollowService;
use Aiya\Core\Domain\Notification\NotificationActions;
use Aiya\Core\Domain\Notification\NotificationService;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use PHPUnit\Framework\TestCase;
use WP_Post;
use wpdb;

/**
 * The publish-side notification legs: a pending submission's approval tells
 * its author (a direct draft publish is the author's own doing), body
 * mentions fan out directed rows on first publication with the follower
 * sweep deduplicating them (a mentioned follower takes the mention row,
 * never both), and a credit top-up tells its holder only when the
 * operator's hand did it — check-ins and membership cycles stay silent.
 *
 * Everything runs through the real NotificationService on the wpdb double,
 * so the assertions read the rows a live site would have stored.
 */
final class NotificationPublishLegsTest extends TestCase
{
    private const TABLE = 'wp_aiya_notifications';
    private const FOLLOWS = 'wp_aiya_user_follows';

    /** Author id, mentioned user id, mentioned-follower id, clean follower id. */
    private int $author;
    private int $mentioned;
    private int $followerMentioned;
    private int $followerPlain;

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new wpdb();
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_user_meta'] = [];
        $GLOBALS['__aiya_test_users'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_mails'] = [];

        global $wpdb;
        $wpdb->aiya_test_rows[self::FOLLOWS] = [];

        $this->author = $this->user(11, '作者十一');
        $this->mentioned = $this->user(12, '被提及十二');
        $this->followerMentioned = $this->user(13, '粉丝十三', followed: true);
        $this->followerPlain = $this->user(14, '粉丝十四', followed: true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    private function user(int $id, string $display, bool $followed = false): int
    {
        $GLOBALS['__aiya_test_users'][$id] = new \WP_User((object) [
            'ID' => $id,
            'user_nicename' => 'u' . $id,
            'display_name' => $display,
        ]);
        global $wpdb;
        // The display-name leg of mention resolution reads wp_users rows.
        $wpdb->aiya_test_rows['wp_users'][] = ['ID' => $id, 'display_name' => $display];
        if ($followed) {
            $wpdb->aiya_test_rows[self::FOLLOWS][] = [
                'id' => count($wpdb->aiya_test_rows[self::FOLLOWS]) + 1,
                'follower_id' => $id,
                'followed_id' => $this->author ?? 11,
            ];
        }

        return $id;
    }

    private function actions(): NotificationActions
    {
        return new NotificationActions(
            new NotificationService(),
            new FollowService(),
            new FavoriteService(),
            new DiscussionService(),
            new SmiliesRenderer(new SmiliesRegistry('/none', '/none')),
            new Mentions()
        );
    }

    private function post(int $id, string $status, string $content): WP_Post
    {
        $post = new WP_Post((object) [
            'ID' => $id,
            'post_author' => $this->author,
            'post_type' => 'post',
            'post_status' => $status,
            'post_name' => 'slug-' . $id,
            'post_title' => '标题' . $id,
            'post_content' => $content,
        ]);
        $GLOBALS['__aiya_test_posts'][$id] = $post;

        return $post;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        global $wpdb;

        return $wpdb->aiya_test_rows[self::TABLE] ?? [];
    }

    /** @return list<string> */
    private function typesFor(int $userId): array
    {
        return array_map(
            static fn (array $r) => $r['type'],
            array_values(array_filter($this->rows(), static fn (array $r) => (int) $r['user_id'] === $userId))
        );
    }

    public function testApprovalTellsTheAuthorPendingOnly(): void
    {
        $this->actions()->onPostTransition('publish', 'pending', $this->post(31, 'publish', '<p>正文。</p>'));

        self::assertSame(['post_approved'], $this->typesFor($this->author), 'the pending author learns of the approval');
    }

    public function testADirectDraftPublishStaysSilentTowardItsAuthor(): void
    {
        $this->actions()->onPostTransition('publish', 'draft', $this->post(32, 'publish', '<p>正文。</p>'));

        self::assertSame([], $this->typesFor($this->author), 'publishing one’s own draft is one’s own doing');
    }

    public function testBodyMentionsFanOutAndDedupAgainstTheFollowerSweep(): void
    {
        $this->actions()->onPostTransition('publish', 'draft', $this->post(33, 'publish', '<p>叫 @被提及十二 和 @粉丝十三 来看。</p>'));

        self::assertSame(['post_mentioned'], $this->typesFor($this->mentioned));
        self::assertSame(['post_mentioned'], $this->typesFor($this->followerMentioned), 'the mention row replaces the sweep row, never both');
        self::assertSame(['followed_published'], $this->typesFor($this->followerPlain), 'an unmentioned follower still gets the sweep');
        self::assertSame([], $this->typesFor($this->author), 'the author neither mentions themselves nor sweeps themselves');
    }

    public function testBodyMentionsCoverResourcesButNotTheFollowerSweep(): void
    {
        $post = $this->post(34, 'publish', '<p>@被提及十二 看这个资源。</p>');
        $post->post_type = 'resource';

        $this->actions()->onPostTransition('publish', 'draft', $post);

        self::assertSame(['post_mentioned'], $this->typesFor($this->mentioned), 'a directed mention is type-agnostic');
        self::assertSame([], $this->typesFor($this->followerPlain), 'the follower sweep stays article-only');
    }

    public function testAnAdminTopUpNotifiesButAutomaticSourcesStaySilent(): void
    {
        $actions = $this->actions();

        $actions->onCreditGranted($this->mentioned, 100, 'admin');
        self::assertSame(['credit_granted'], $this->typesFor($this->mentioned));

        $actions->onCreditGranted($this->mentioned, 5, 'checkin');
        $actions->onCreditGranted($this->mentioned, 30, 'membership_cycle');
        $actions->onCreditGranted($this->mentioned, 50, 'code');
        self::assertSame(['credit_granted'], $this->typesFor($this->mentioned), 'routine bookkeeping never pages the holder');
    }

    /** The activation receipt (2026-10-03): the activation mail doubles as
        the holder's bill — tier, order id and the coverage window, with
        the CTA on the front end's membership page. */
    public function testMembershipActivationSendsTheReceiptMail(): void
    {
        global $wpdb;
        $GLOBALS['__aiya_test_options']['blogname'] = 'AIYA 测试站';
        $GLOBALS['__aiya_test_users'][21] = ['user_email' => 'member@example.test'];
        $wpdb->aiya_test_rows['wp_aiya_memberships'] = [
            [
                'user_id' => 21,
                'order_id' => 'probe-order-21',
                'tier_key' => 'probe_tier',
                'tier_name' => '季档',
                'cycle_days' => 90,
                'credits_per_cycle' => 0,
                'cycles_total' => 1,
                'cycles_granted' => 0,
                'starts_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
                'ends_at' => gmdate('Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS),
                'status' => 'active',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
        ];

        (new NotificationActions(
            new NotificationService(),
            new FollowService(),
            new FavoriteService(),
            new DiscussionService(),
            new SmiliesRenderer(new SmiliesRegistry('/none', '/none')),
            new Mentions()
        ))->onMembershipActivated(21, 'probe-order-21');

        // the in-site row keeps flowing
        self::assertSame(['sponsor_activated'], $this->typesFor(21));

        // the receipt: one branded mail to the holder
        $mails = $GLOBALS['__aiya_test_mails'];
        self::assertCount(1, $mails);
        self::assertSame('member@example.test', $mails[0]['to']);
        self::assertSame('[AIYA 测试站] Thank you — your membership is now active.', $mails[0]['subject']);
        $message = (string) $mails[0]['message'];
        self::assertStringContainsString(MailTemplate::SHELL_MARKER, $message);
        self::assertStringContainsString('季档', $message);
        self::assertStringContainsString('probe-order-21', $message);
        self::assertStringContainsString('multiple cycles', $message, 'the multi-cycle stacking copy rides the receipt (unit tests see the source locale)');
        self::assertStringContainsString('href="https://aiya.test/profile/me/"', $message);
    }
}
