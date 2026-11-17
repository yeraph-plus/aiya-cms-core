<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../Fixture/AccountDeletionTestWpdb.php';
    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    if (!function_exists('wp_check_password')) {
        /** The hash convention: the stored hash is 'hashed:' . the one right password. */
        function wp_check_password(string $password, string $hash, int|string $userId = ''): bool
        {
            return $hash === 'hashed:' . $password;
        }
    }

    if (!function_exists('wp_delete_user')) {
        /**
         * The core deletion, honed to what the executor's assertions lean
         * on: the delete_user hook fires first (AvatarModule's purge rides
         * it in production), authored posts follow the reassign verdict —
         * they change hands when a target is given and die otherwise —
         * and the holder's usermeta rows and users row always go.
         */
        function wp_delete_user(int $id, mixed $reassign = null): bool
        {
            if (!isset($GLOBALS['__aiya_test_users'][$id])) {
                return false;
            }
            do_action('delete_user', $id, $reassign);
            if (($db = $GLOBALS['wpdb'] ?? null) instanceof \Aiya\Core\Tests\Unit\AccountDeletionTestWpdb) {
                if ($reassign !== null) {
                    $db->query("UPDATE {$db->posts} SET post_author = " . (int) $reassign . " WHERE post_author = " . (int) $id);
                } else {
                    $db->query("DELETE FROM {$db->posts} WHERE post_author = " . (int) $id);
                }
            }
            unset($GLOBALS['__aiya_test_users'][$id]);
            unset($GLOBALS['__aiya_test_user_meta'][$id]);
            do_action('deleted_user', $id, $reassign);

            return true;
        }
    }
}

namespace Aiya\Core\Tests\Unit {
    use Aiya\Core\Api\Rest\BanGate;
    use Aiya\Core\Domain\Identity\AccountDeletionService;
    use Aiya\Core\Domain\Identity\IdentityModule;
    use Aiya\Core\Domain\Identity\LastLogin;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_User;

    /**
     * The self-service deletion flow (0.136.0): re-auth'd request, the
     * one-time cron event, the lock the gate answers, the recovery
     * semantics (any sign-in withdraws), and the executor's guard matrix
     * plus sweep. The sweep shapes run against the statement-level double
     * (AccountDeletionTestWpdb); the runtime MySQL probe re-walks the same
     * matrix against the real tables.
     */
    final class AccountDeletionServiceTest extends TestCase
    {
        private const HOLDER = 5;
        private const OTHER = 9;
        private const BYSTANDER = 7;
        private const REASSIGN = AccountDeletionService::REASSIGN_USER_ID;
        private const HOOK = IdentityModule::ACCOUNT_DELETE_HOOK;

        private AccountDeletionTestWpdb $db;

        private array $savedFilters;

        protected function setUp(): void
        {
            $this->savedFilters = $GLOBALS['__aiya_test_filters'] ?? [];
            \Aiya\Core\Domain\Mail\AccountDeletionMail::register();
            global $wpdb;
            $this->db = new AccountDeletionTestWpdb();
            $wpdb = $this->db;
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_users'] = [];
            $GLOBALS['__aiya_test_cron'] = [];
            $GLOBALS['__aiya_test_mails'] = [];
            $GLOBALS['__aiya_test_current_user_id'] = self::HOLDER;
            // The holder is a plain subscriber: every capability ask fails.
            $GLOBALS['__aiya_test_caps'] = false;
            $this->seedHolder();
        }

        protected function tearDown(): void
        {
            $GLOBALS['__aiya_test_filters'] = $this->savedFilters;
            unset($GLOBALS['wpdb']);
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_users'] = [];
            $GLOBALS['__aiya_test_cron'] = [];
            $GLOBALS['__aiya_test_mails'] = [];
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            unset($GLOBALS['__aiya_test_caps']);
        }

        private function seedHolder(): WP_User
        {
            $GLOBALS['__aiya_test_users'][self::REASSIGN] = ['user_pass' => 'hashed:root', 'user_email' => 'owner@example.test'];
            $GLOBALS['__aiya_test_users'][self::HOLDER] = ['user_pass' => 'hashed:secret', 'user_email' => 'holder@example.test'];
            $GLOBALS['__aiya_test_users'][self::OTHER] = ['user_pass' => 'hashed:secret', 'user_email' => 'other@example.test'];

            return get_userdata(self::HOLDER);
        }

        /** The successful request's marker, for the key-carrying assertions. */
        private function requestPending(): array
        {
            $user = get_userdata(self::HOLDER);
            $result = AccountDeletionService::request($user, 'secret');
            self::assertTrue($result);
            $marker = AccountDeletionService::marker(self::HOLDER);
            self::assertNotNull($marker);

            return $marker;
        }

        public function testAValidRequestLocksSchedulesAndMails(): void
        {
            $before = time();
            $marker = $this->requestPending();
            $after = time();

            self::assertTrue(AccountDeletionService::isPending(self::HOLDER));
            self::assertSame($marker['requested_at'] + AccountDeletionService::GRACE_DAYS * DAY_IN_SECONDS, $marker['effective_at']);
            self::assertGreaterThanOrEqual($before, $marker['requested_at']);
            self::assertLessThanOrEqual($after, $marker['requested_at']);

            // Exactly one one-time event, aimed at the effective moment.
            self::assertCount(1, $GLOBALS['__aiya_test_cron']);
            $event = $GLOBALS['__aiya_test_cron'][0];
            self::assertSame(self::HOOK, $event['hook']);
            self::assertSame($marker['effective_at'], $event['timestamp']);
            self::assertSame([self::HOLDER, $marker['key']], $event['args']);

            // One notice mail reached the holder.
            self::assertCount(1, $GLOBALS['__aiya_test_mails']);
            self::assertSame('holder@example.test', $GLOBALS['__aiya_test_mails'][0]['to']);
        }

        public function testAWrongPasswordRefusesWithoutState(): void
        {
            $result = AccountDeletionService::request(get_userdata(self::HOLDER), 'nope');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_wrong_password', $result->get_error_code());
            self::assertFalse(AccountDeletionService::isPending(self::HOLDER));
            self::assertSame([], $GLOBALS['__aiya_test_cron']);
            self::assertSame([], $GLOBALS['__aiya_test_mails']);
        }

        public function testASecondRequestIsRefusedAsPending(): void
        {
            $this->requestPending();
            $second = AccountDeletionService::request(get_userdata(self::HOLDER), 'secret');

            self::assertInstanceOf(WP_Error::class, $second);
            self::assertSame('aiya_deletion_pending', $second->get_error_code());
            self::assertSame(409, $second->get_error_data()['status']);
            self::assertCount(1, $GLOBALS['__aiya_test_cron'], 'the original event must stay untouched');
        }

        public function testStaffAccountsCannotRequest(): void
        {
            // publish_posts is the hard line: author and above are out of
            // the flow entirely (contributors and subscribers are in).
            $GLOBALS['__aiya_test_caps'] = true;
            $staff = AccountDeletionService::request(get_userdata(self::HOLDER), 'secret');
            self::assertInstanceOf(WP_Error::class, $staff);
            self::assertSame('aiya_account_staff', $staff->get_error_code());
            self::assertSame([], $GLOBALS['__aiya_test_cron']);
        }

        public function testCancelRemovesMarkerAndEvent(): void
        {
            $marker = $this->requestPending();

            self::assertTrue(AccountDeletionService::cancel(self::HOLDER));
            self::assertFalse(AccountDeletionService::isPending(self::HOLDER));
            self::assertSame([], $GLOBALS['__aiya_test_cron']);

            // Nothing waits any more — a second cancel is a 404.
            $again = AccountDeletionService::cancel(self::HOLDER);
            self::assertInstanceOf(WP_Error::class, $again);
            self::assertSame('aiya_no_pending_deletion', $again->get_error_code());
            self::assertSame(404, $again->get_error_data()['status']);
            self::assertNotNull($marker);
        }

        public function testSigningInWithdrawsTheRequest(): void
        {
            $this->requestPending();

            self::assertTrue(AccountDeletionService::cancelIfPending(self::HOLDER));
            self::assertFalse(AccountDeletionService::isPending(self::HOLDER));
            self::assertSame([], $GLOBALS['__aiya_test_cron']);
            self::assertFalse(AccountDeletionService::cancelIfPending(self::HOLDER), 'a second pass answers no-op');
        }

        public function testAPendingHolderIsLockedAgainstWritesButCanWithdraw(): void
        {
            $marker = $this->requestPending();

            $refused = BanGate::guard(null, null, new FakeRestRequest('/aiya/core/v1/credits/redeem', 'POST'));
            self::assertInstanceOf(WP_Error::class, $refused);
            self::assertSame('aiya_account_pending_deletion', $refused->get_error_code());
            self::assertSame(403, $refused->get_error_data()['status']);

            // The withdrawal route stays reachable while pending — the
            // handler's own rules keep the POST variant honest.
            self::assertNull(BanGate::guard(null, null, new FakeRestRequest('/aiya/core/v1/users/me/account-deletion', 'DELETE')));
            self::assertNotNull($marker);
        }

        public function testTheExecutorSweepsEveryHolderSurface(): void
        {
            $this->seedCommunity();
            $marker = $this->requestPending();

            \Aiya\Core\Plugin::accountDeletionExecutor()->execute(self::HOLDER, $marker['key']);

            // The holder row and every meta are gone; the other survives.
            self::assertFalse(get_userdata(self::HOLDER));
            self::assertNotFalse(get_userdata(self::OTHER));

            // Relation surfaces lose the holder's rows, keep the rest.
            self::assertCount(1, $this->db->rows['wp_aiya_user_favorites']);
            self::assertSame(self::OTHER, (int) $this->db->rows['wp_aiya_user_favorites'][0]['user_id']);
            foreach ($this->db->rows['wp_aiya_user_follows'] as $row) {
                self::assertNotContains(self::HOLDER, [(int) $row['follower_id'], (int) $row['followed_id']]);
            }
            self::assertCount(1, $this->db->rows['wp_aiya_user_follows'], 'only the 9→7 edge survives');

            // Notifications: targeted rows and actor references go, the
            // broadcast row survives.
            foreach ($this->db->rows['wp_aiya_notifications'] as $row) {
                self::assertNotSame(self::HOLDER, (int) $row['user_id']);
                self::assertNotSame(self::HOLDER, (int) ($row['actor_id'] ?? 0));
            }
            self::assertCount(2, $this->db->rows['wp_aiya_notifications']);

            self::assertCount(1, $this->db->rows['wp_aiya_credit_entries']);
            self::assertSame([], $this->db->rows['wp_aiya_memberships']);
            self::assertCount(1, $this->db->rows['wp_aiya_stats_active']);
            self::assertCount(1, $this->db->rows['wp_aiya_chat_messages']);
            self::assertSame('u' . self::OTHER, (string) $this->db->rows['wp_aiya_chat_messages'][0]['session_id']);
            self::assertCount(1, $this->db->rows['wp_aiya_auth_tokens']);

            // The redemption audit survives with the holder reference cut.
            $codes = array_column($this->db->rows['wp_aiya_redeem_codes'], 'user_id', 'code');
            self::assertNull($codes['HOLDERCODE']);
            self::assertSame('', (string) $codes['SPARE']);

            // The money archive: paid orders are forever, dangling id and all.
            self::assertCount(1, $this->db->rows['wp_aiya_payment_orders']);
            self::assertSame(self::HOLDER, (int) $this->db->rows['wp_aiya_payment_orders'][0]['user_id']);

            // Community: both threads survive — the holder's own changed
            // hands to REASSIGN_USER_ID (everyone else's replies under it
            // intact), the survivor thread keeps its owner. The holder's
            // replies and likes are gone everywhere and every counter was
            // recomputed through the discussion domain's routine.
            self::assertCount(2, $this->db->rows['wp_aiya_discussions']);
            $threads = array_column($this->db->rows['wp_aiya_discussions'], null, 'id');
            $own = $threads[1];
            self::assertSame(self::REASSIGN, (int) $own['user_id'], 'the departing holder\'s thread changes hands');
            self::assertSame(0, (int) $own['reply_count']);
            self::assertSame(0, (int) $own['like_count']);
            self::assertSame(0, (int) $own['last_reply_user_id']);
            self::assertNull($own['last_reply_at']);
            self::assertSame('2026-01-01 00:00:00', (string) $own['bumped_at'], 'activity falls back to the thread creation');
            $survivor = $threads[2];
            self::assertSame(self::OTHER, (int) $survivor['user_id']);
            self::assertSame(1, (int) $survivor['reply_count']);
            self::assertSame(1, (int) $survivor['like_count']);
            self::assertSame(self::OTHER, (int) $survivor['last_reply_user_id']);
            self::assertSame('2026-01-03 12:00:00', (string) $survivor['last_reply_at']);
            foreach ($this->db->rows['wp_aiya_discussion_replies'] as $row) {
                self::assertNotSame(self::HOLDER, (int) $row['user_id']);
            }
            self::assertCount(1, $this->db->rows['wp_aiya_discussion_replies']);
            foreach ($this->db->rows['wp_aiya_discussion_likes'] as $row) {
                self::assertNotSame(self::HOLDER, (int) $row['user_id']);
            }
            self::assertCount(1, $this->db->rows['wp_aiya_discussion_likes']);

            // Comments: anonymized in the core eraser's exact shape — the
            // row survives as a guest comment.
            self::assertCount(1, $this->db->rows['wp_comments']);
            $comment = $this->db->rows['wp_comments'][0];
            self::assertSame('Anonymous', $comment['comment_author']);
            self::assertSame('', $comment['comment_author_email']);
            self::assertSame('', $comment['comment_author_url']);
            self::assertSame('', $comment['comment_agent']);
            self::assertSame('203.0.113.0', $comment['comment_author_IP'], 'the IP truncates like wp_privacy_anonymize_data does');
            self::assertSame(0, (int) $comment['user_id']);

            // Content: authored posts change hands instead of dying.
            $posts = array_column($this->db->rows['wp_posts'], 'post_author', 'post_type');
            self::assertSame(self::REASSIGN, (int) $posts['post'], 'the holder\'s post lands on the reassign target');
            self::assertSame(self::OTHER, (int) $posts['resource']);
        }

        public function testTheExecutorGuardsRefuseAndWithdraw(): void
        {
            $this->seedCommunity();

            // Staff between request and execution: the countdown withdraws.
            $marker = $this->requestPending();
            $GLOBALS['__aiya_test_caps'] = true;
            \Aiya\Core\Plugin::accountDeletionExecutor()->execute(self::HOLDER, $marker['key']);
            self::assertNotFalse(get_userdata(self::HOLDER));
            self::assertFalse(AccountDeletionService::isPending(self::HOLDER));

            // Coming back during the window: the stamp beats the request.
            $GLOBALS['__aiya_test_caps'] = false;
            $marker = $this->requestPending();
            update_user_meta(self::HOLDER, LastLogin::META_KEY, time() + 10);
            \Aiya\Core\Plugin::accountDeletionExecutor()->execute(self::HOLDER, $marker['key']);
            self::assertNotFalse(get_userdata(self::HOLDER));
            self::assertFalse(AccountDeletionService::isPending(self::HOLDER));

            // The reassign target vanished: withdrawn, user intact —
            // content has to land somewhere.
            delete_user_meta(self::HOLDER, LastLogin::META_KEY);
            $marker = $this->requestPending();
            unset($GLOBALS['__aiya_test_users'][self::REASSIGN]);
            \Aiya\Core\Plugin::accountDeletionExecutor()->execute(self::HOLDER, $marker['key']);
            self::assertNotFalse(get_userdata(self::HOLDER));
            self::assertFalse(AccountDeletionService::isPending(self::HOLDER));

            // A foreign key (a stale event for another round): untouched.
            $this->db->query("DELETE FROM {$this->db->posts} WHERE post_author = " . self::HOLDER);
            $marker = $this->requestPending();
            \Aiya\Core\Plugin::accountDeletionExecutor()->execute(self::HOLDER, 'wrong-key');
            self::assertNotFalse(get_userdata(self::HOLDER));
            self::assertTrue(AccountDeletionService::isPending(self::HOLDER));
            self::assertCount(1, $GLOBALS['__aiya_test_cron']);
        }

        public function testALockContentionSkipsAndKeepsTheMarker(): void
        {
            $marker = $this->requestPending();
            $this->db->lockAvailable = false;

            \Aiya\Core\Plugin::accountDeletionExecutor()->execute(self::HOLDER, $marker['key']);

            self::assertNotFalse(get_userdata(self::HOLDER));
            self::assertTrue(AccountDeletionService::isPending(self::HOLDER), 'the reconcile must find the skipped round');
        }

        public function testAFailedSweepStatementRollsEverythingBack(): void
        {
            $this->seedCommunity();
            $marker = $this->requestPending();
            $this->db->failSweep = true;

            \Aiya\Core\Plugin::accountDeletionExecutor()->execute(self::HOLDER, $marker['key']);

            // Nothing committed: the holder, the seeded rows and the marker
            // all survive for the daily reconcile's retry round — a server
            // -side rollback must never be autocommitted on top of.
            self::assertNotFalse(get_userdata(self::HOLDER));
            self::assertTrue(AccountDeletionService::isPending(self::HOLDER));
            self::assertCount(2, $this->db->rows['wp_aiya_discussions']);
            self::assertSame(self::HOLDER, (int) $this->db->rows['wp_aiya_discussions'][0]['user_id']);
            self::assertCount(3, $this->db->rows['wp_aiya_user_follows'], 'the sweep stopped at the first failure');
            self::assertContains('ROLLBACK', $this->db->aiya_test_sql);
            self::assertNotContains('COMMIT', $this->db->aiya_test_sql);
            self::assertStringContainsString('RELEASE_LOCK', end($this->db->aiya_test_sql));
        }

        public function testCleanupFailureStopsLaterDomainsAndReleasesTheLock(): void
        {
            $marker = $this->requestPending();
            $calls = [];
            $executor = new \Aiya\Core\Runtime\AccountDeletionExecutor([
                static function (int $userId) use (&$calls): bool {
                    $calls[] = $userId;

                    return false;
                },
                static function (int $userId) use (&$calls): bool {
                    $calls[] = 'must not run';

                    return true;
                },
            ]);
            $executor->execute(self::HOLDER, $marker['key']);

            self::assertSame([self::HOLDER], $calls);
            self::assertNotFalse(get_userdata(self::HOLDER));
            self::assertTrue(AccountDeletionService::isPending(self::HOLDER));
            self::assertContains('ROLLBACK', $this->db->aiya_test_sql);
            self::assertStringContainsString('RELEASE_LOCK', end($this->db->aiya_test_sql));
        }

        public function testCleanupExceptionRollsBackAndReleasesTheLock(): void
        {
            $marker = $this->requestPending();
            $executor = new \Aiya\Core\Runtime\AccountDeletionExecutor([
                static function (int $userId): bool {
                    throw new \RuntimeException('cleanup failed');
                },
            ]);
            try {
                $executor->execute(self::HOLDER, $marker['key']);
                self::fail('the failure must remain observable');
            } catch (\RuntimeException $error) {
                self::assertSame('cleanup failed', $error->getMessage());
            }
            self::assertTrue(AccountDeletionService::isPending(self::HOLDER));
            self::assertContains('ROLLBACK', $this->db->aiya_test_sql);
            self::assertStringContainsString('RELEASE_LOCK', end($this->db->aiya_test_sql));
        }

        public function testTheReconcileReschedulesDroppedEvents(): void
        {
            $marker = $this->requestPending();

            // A live event: the reconcile adds nothing.
            AccountDeletionService::reconcilePending();
            self::assertCount(1, $GLOBALS['__aiya_test_cron']);

            // The event was dropped (deactivation, cron-array wipe): the
            // same marker re-schedules with the same args.
            wp_clear_scheduled_hook(self::HOOK, [self::HOLDER, $marker['key']]);
            AccountDeletionService::reconcilePending();
            self::assertCount(1, $GLOBALS['__aiya_test_cron']);
            $event = $GLOBALS['__aiya_test_cron'][0];
            self::assertSame(self::HOOK, $event['hook']);
            self::assertSame([self::HOLDER, $marker['key']], $event['args']);
            self::assertGreaterThanOrEqual(time(), $event['timestamp']);
        }

        public function testTheReconcileDropsCorruptMarkers(): void
        {
            update_user_meta(self::HOLDER, AccountDeletionService::MARKER_META, 'not-a-marker');

            AccountDeletionService::reconcilePending();

            self::assertSame('', get_user_meta(self::HOLDER, AccountDeletionService::MARKER_META, true));
            self::assertSame([], $GLOBALS['__aiya_test_cron']);
        }

        public function testTheStampReadsAndWritesThroughOneKey(): void
        {
            self::assertSame(0, LastLogin::of(self::HOLDER));
            LastLogin::stamp(self::HOLDER);
            self::assertGreaterThan(0, LastLogin::of(self::HOLDER));
            self::assertSame(0, LastLogin::of(0), 'zero holders never stamp');
        }

        /** The community the executor sweeps: two holders, two threads, one paid order. */
        private function seedCommunity(): void
        {
            $db = $this->db;
            $db->seed('wp_aiya_user_favorites', ['user_id' => self::HOLDER, 'post_id' => 100]);
            $db->seed('wp_aiya_user_favorites', ['user_id' => self::OTHER, 'post_id' => 101]);
            $db->seed('wp_aiya_user_follows', ['follower_id' => self::HOLDER, 'followed_id' => self::OTHER]);
            $db->seed('wp_aiya_user_follows', ['follower_id' => self::OTHER, 'followed_id' => self::HOLDER]);
            $db->seed('wp_aiya_user_follows', ['follower_id' => self::OTHER, 'followed_id' => self::BYSTANDER]);
            $db->seed('wp_aiya_notifications', ['user_id' => self::HOLDER, 'actor_id' => 0, 'type' => 'post_commented']);
            $db->seed('wp_aiya_notifications', ['user_id' => 0, 'actor_id' => 0, 'type' => 'announcement']);
            $db->seed('wp_aiya_notifications', ['user_id' => self::BYSTANDER, 'actor_id' => self::HOLDER, 'type' => 'new_follower']);
            $db->seed('wp_aiya_notifications', ['user_id' => self::BYSTANDER, 'actor_id' => self::OTHER, 'type' => 'new_follower']);
            $db->seed('wp_aiya_credit_entries', ['user_id' => self::HOLDER, 'direction' => 'in', 'remaining' => 5]);
            $db->seed('wp_aiya_credit_entries', ['user_id' => self::OTHER, 'direction' => 'in', 'remaining' => 9]);
            $db->seed('wp_aiya_memberships', ['user_id' => self::HOLDER, 'status' => 'active']);
            $db->seed('wp_aiya_stats_active', ['month' => '2026-11', 'user_id' => self::HOLDER]);
            $db->seed('wp_aiya_stats_active', ['month' => '2026-11', 'user_id' => self::OTHER]);
            $db->seed('wp_aiya_redeem_codes', ['code' => 'HOLDERCODE', 'user_id' => self::HOLDER, 'status' => 1]);
            $db->seed('wp_aiya_redeem_codes', ['code' => 'SPARE', 'user_id' => '', 'status' => 0]);
            $db->seed('wp_aiya_chat_messages', ['session_id' => 'u' . self::HOLDER, 'text' => 'a']);
            $db->seed('wp_aiya_chat_messages', ['session_id' => 'u' . self::OTHER, 'text' => 'b']);
            $db->seed('wp_aiya_auth_tokens', ['user_id' => self::HOLDER, 'token_hash' => 'h5']);
            $db->seed('wp_aiya_auth_tokens', ['user_id' => self::OTHER, 'token_hash' => 'h9']);
            $db->seed('wp_aiya_payment_orders', ['user_id' => self::HOLDER, 'order_id' => 'ord1', 'status' => 'paid']);
            // The holder's own thread (with its reply and like) and the
            // survivor thread the holder replied to last and liked.
            $db->seed('wp_aiya_discussions', [
                'user_id' => self::HOLDER, 'title' => 'own', 'reply_count' => 1, 'like_count' => 1,
                'last_reply_user_id' => self::HOLDER, 'last_reply_at' => '2026-01-02 00:00:00',
                'bumped_at' => '2026-01-02 00:00:00', 'created_at' => '2026-01-01 00:00:00',
            ]);
            $db->seed('wp_aiya_discussions', [
                'user_id' => self::OTHER, 'title' => 'other', 'reply_count' => 2, 'like_count' => 2,
                'last_reply_user_id' => self::HOLDER, 'last_reply_at' => '2026-01-04 00:00:00',
                'bumped_at' => '2026-01-04 00:00:00', 'created_at' => '2026-01-03 00:00:00',
            ]);
            $db->seed('wp_aiya_discussion_replies', ['thread_id' => 1, 'user_id' => self::HOLDER, 'created_at' => '2026-01-02 00:00:00']);
            $db->seed('wp_aiya_discussion_replies', ['thread_id' => 2, 'user_id' => self::OTHER, 'created_at' => '2026-01-03 12:00:00']);
            $db->seed('wp_aiya_discussion_replies', ['thread_id' => 2, 'user_id' => self::HOLDER, 'created_at' => '2026-01-04 00:00:00']);
            $db->seed('wp_aiya_discussion_likes', ['thread_id' => 1, 'user_id' => self::HOLDER]);
            $db->seed('wp_aiya_discussion_likes', ['thread_id' => 2, 'user_id' => self::HOLDER]);
            $db->seed('wp_aiya_discussion_likes', ['thread_id' => 2, 'user_id' => self::OTHER]);
            // Content ownership: the holder wrote a plain post (reassigns
            // to user 1 at execution), the other wrote a resource (untouched).
            $db->seed('wp_posts', ['post_author' => self::HOLDER, 'post_type' => 'post']);
            $db->seed('wp_posts', ['post_author' => self::OTHER, 'post_type' => 'resource']);
            // A logged-in comment by the holder — the anonymization's input.
            $db->seed('wp_comments', [
                'comment_ID' => 55,
                'user_id' => self::HOLDER,
                'comment_author' => 'Holder',
                'comment_author_email' => 'holder@example.test',
                'comment_author_IP' => '203.0.113.7',
                'comment_author_url' => 'https://holder.example',
                'comment_agent' => 'Mozilla/5.0',
            ]);
        }
    }
}
