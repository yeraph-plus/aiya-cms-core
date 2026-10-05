<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles for the nine WordPress account functions
     * AccountService calls that tests/bootstrap.php does not provide.
     * Guarded (a bootstrap addition wins by load order); every write lands
     * in the __aiya_test_account_* globals the assertions read back.
     */

    if (!function_exists('wp_check_password')) {
        /** The hash convention: the stored hash is 'hashed:' . the one right password. */
        function wp_check_password(string $password, string $hash, int|string $userId = ''): bool
        {
            return $hash === 'hashed:' . $password;
        }
    }

    if (!function_exists('wp_set_password')) {
        /** Mirrors the core write: the stored hash becomes the new password's. */
        function wp_set_password(string $password, int $userId): void
        {
            $GLOBALS['__aiya_test_account_events'][] = 'set_password';
            $GLOBALS['__aiya_test_users'][$userId]['user_pass'] = 'hashed:' . $password;
        }
    }

    if (!function_exists('wp_clear_auth_cookie')) {
        function wp_clear_auth_cookie(): void
        {
            $GLOBALS['__aiya_test_account_events'][] = 'clear_auth_cookie';
        }
    }

    if (!function_exists('wp_update_user')) {
        /** @param array<string, mixed> $userdata */
        function wp_update_user(array $userdata): int|WP_Error
        {
            $GLOBALS['__aiya_test_account_events'][] = ['update_user', $userdata];
            if ($GLOBALS['__aiya_test_account_update_user_error'] ?? false) {
                return new WP_Error('aiya_test_db', 'staged profile write failure');
            }

            return (int) ($userdata['ID'] ?? 0);
        }
    }

    if (!function_exists('email_exists')) {
        /** Fixture: $GLOBALS['__aiya_test_account_emails'][email] = user id. */
        function email_exists(string $email): int|false
        {
            return $GLOBALS['__aiya_test_account_emails'][$email] ?? false;
        }
    }

    if (!function_exists('username_exists')) {
        /** Fixture: a staged probe callable, or empty means never taken. */
        function username_exists(string $username): int|false
        {
            $probe = $GLOBALS['__aiya_test_account_username_probe'] ?? null;
            if (is_callable($probe)) {
                return $probe($username);
            }

            return false;
        }
    }

    if (!function_exists('wp_generate_uuid4')) {
        /** Deterministic mint: a counted sequence the assertions can name. */
        function wp_generate_uuid4(): string
        {
            $GLOBALS['__aiya_test_account_uuid_counter'] = ($GLOBALS['__aiya_test_account_uuid_counter'] ?? 0) + 1;

            return sprintf('uuid-%04d-0000-4000-8000', $GLOBALS['__aiya_test_account_uuid_counter']);
        }
    }

    if (!function_exists('wp_create_user')) {
        function wp_create_user(string $username, string $password, string $email): int|WP_Error
        {
            $GLOBALS['__aiya_test_account_events'][] = ['create_user', $username, $email];
            if ($GLOBALS['__aiya_test_account_create_user_error'] ?? false) {
                return new WP_Error('aiya_test_db', 'staged user creation failure');
            }

            return (int) ($GLOBALS['__aiya_test_account_next_user_id'] ?? 55);
        }
    }

    if (!function_exists('wp_send_new_user_notifications')) {
        function wp_send_new_user_notifications(int $userId, string $notify = 'both'): void
        {
            $GLOBALS['__aiya_test_account_events'][] = ['notify', $userId, $notify];
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Domain\Identity\AccountService;
    use Aiya\Core\Domain\Identity\PasswordPolicy;
    use Aiya\Core\Domain\Identity\TokenStore;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_User;

    /**
     * The wpdb slice with the revocation event marker: TokenStore's sweep
     * DELETE runs through here, so the revoke step lands in the same event
     * stream as the password writes — which is what makes the ordering
     * invariant (old tokens never outlive the change) observable.
     */
    final class AccountWpdb extends \wpdb
    {
        public function query(string $sql): int
        {
            if (str_contains($sql, 'aiya_auth_tokens')) {
                $GLOBALS['__aiya_test_account_events'][] = 'revoke_all';
            }

            return parent::query($sql);
        }
    }

    /**
     * The wpdb slice with a staged hard failure of the token sweep: the
     * failure mode (prepare answering non-string, revokeAll() false) is
     * not reachable through the bootstrap double's string-typed prepare().
     */
    final class RevocationFailsWpdb
    {
        public string $prefix = 'wp_';

        public function prepare(string $sql, mixed ...$args): ?string
        {
            $index = 0;
            $out = (string) preg_replace_callback(
                '/%[ids]/',
                static fn (array $match): string => (string) ($args[$index++] ?? ''),
                $sql
            );

            return str_contains($out, 'aiya_auth_tokens') ? null : $out;
        }
    }

    /**
     * The account-write invariants: moving the mailbox demands the current
     * password first, and the password change sweeps every session BEFORE
     * the password moves — a failed sweep aborts while the old password
     * still applies. Registration mints its login names and keeps a fresh
     * mailbox a distinct signal.
     */
    final class AccountServiceTest extends TestCase
    {
        private const OLD_HASH = 'hashed:secret1';

        protected function setUp(): void
        {
            global $wpdb;
            $wpdb = new AccountWpdb();
            $wpdb->aiya_test_rows['wp_aiya_auth_tokens'] = [
                ['id' => 1, 'token_hash' => 'h-7-a', 'user_id' => 7, 'expires_at' => '2030-01-01 00:00:00'],
                ['id' => 2, 'token_hash' => 'h-7-b', 'user_id' => 7, 'expires_at' => '2030-01-01 00:00:00'],
                ['id' => 3, 'token_hash' => 'h-8-a', 'user_id' => 8, 'expires_at' => '2030-01-01 00:00:00'],
            ];
            $GLOBALS['__aiya_test_users'] = [7 => ['user_pass' => self::OLD_HASH], 8 => true];
            $GLOBALS['__aiya_test_account_events'] = [];
            $GLOBALS['__aiya_test_account_emails'] = [];
            $GLOBALS['__aiya_test_account_uuid_counter'] = 0;
            unset(
                $GLOBALS['__aiya_test_account_update_user_error'],
                $GLOBALS['__aiya_test_account_create_user_error'],
                $GLOBALS['__aiya_test_account_username_probe'],
                $GLOBALS['__aiya_test_account_next_user_id']
            );
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb'], $GLOBALS['__aiya_test_account_events']);
            $GLOBALS['__aiya_test_users'] = [];
        }

        private function service(): AccountService
        {
            return new AccountService(new TokenStore(), new PasswordPolicy());
        }

        private function user(): WP_User
        {
            return new WP_User((object) ['ID' => 7, 'user_email' => 'old@aiya.test', 'user_pass' => self::OLD_HASH]);
        }

        /** @return list<mixed> */
        private function events(): array
        {
            return $GLOBALS['__aiya_test_account_events'] ?? [];
        }

        // --------------------------------------------------- the email gate

        public function testEmailChangeWithoutCurrentPasswordIsRefused(): void
        {
            $result = $this->service()->updateProfile($this->user(), ['ID' => 7, 'user_email' => 'new@aiya.test'], '');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_reauth_required', $result->get_error_code());
            self::assertSame(403, $result->get_error_data()['status'], 'a stolen session must not take over the mailbox');
            self::assertSame([], $this->events(), 'the refused write never reaches the users table');
        }

        public function testEmailChangeWithWrongCurrentPasswordIsRefused(): void
        {
            $result = $this->service()->updateProfile($this->user(), ['ID' => 7, 'user_email' => 'new@aiya.test'], 'wrong-secret');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_reauth_required', $result->get_error_code());
            self::assertSame([], $this->events());
        }

        public function testEmailChangeWithCurrentPasswordWritesTheProfile(): void
        {
            $result = $this->service()->updateProfile($this->user(), ['ID' => 7, 'user_email' => 'new@aiya.test'], 'secret1');

            self::assertTrue($result);
            self::assertSame(
                [['update_user', ['ID' => 7, 'user_email' => 'new@aiya.test']]],
                $this->events()
            );
        }

        public function testUnchangedEmailNeedsNoReauthentication(): void
        {
            $result = $this->service()->updateProfile($this->user(), ['ID' => 7, 'user_email' => 'old@aiya.test'], '');

            self::assertTrue($result, 'the gate guards moves, not re-saves of the same address');
            self::assertSame('old@aiya.test', $this->events()[0][1]['user_email']);
        }

        public function testNonEmailFieldsNeedNoCurrentPassword(): void
        {
            $result = $this->service()->updateProfile($this->user(), ['ID' => 7, 'display_name' => 'Nova'], '');

            self::assertTrue($result);
            self::assertSame('Nova', $this->events()[0][1]['display_name']);
        }

        public function testFailedProfileWriteIsAiyaUpdateFailed(): void
        {
            $GLOBALS['__aiya_test_account_update_user_error'] = true;

            $result = $this->service()->updateProfile($this->user(), ['ID' => 7, 'display_name' => 'Nova'], '');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_update_failed', $result->get_error_code());
            self::assertSame(500, $result->get_error_data()['status']);
        }

        public function testIdOnlyPayloadWritesNothing(): void
        {
            $result = $this->service()->updateProfile($this->user(), ['ID' => 7], '');

            self::assertTrue($result);
            self::assertSame([], $this->events(), 'an ID-only array must not pay wp_update_user\'s empty-content path');
        }

        // ------------------------------------------------- the password change

        public function testWrongCurrentPasswordRefusesTheChange(): void
        {
            $result = $this->service()->changePassword($this->user(), 'wrong-secret', 'n3wSecret9', 'n3wSecret9');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_wrong_password', $result->get_error_code());
            self::assertSame(400, $result->get_error_data()['status']);
            self::assertSame([], $this->events());
        }

        public function testShortPasswordIsRefusedByThePolicy(): void
        {
            $result = $this->service()->changePassword($this->user(), 'secret1', 'short1', 'short1');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_invalid_password', $result->get_error_code());
            self::assertSame([], $this->events(), 'a refused password never reaches the sweep or the write');
        }

        public function testMismatchedConfirmationRefusesTheChange(): void
        {
            $result = $this->service()->changePassword($this->user(), 'secret1', 'n3wSecret9', 'different9');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_invalid_password', $result->get_error_code());
            self::assertSame([], $this->events());
        }

        public function testChangeRevokesTokensBeforeThePasswordMoves(): void
        {
            $result = $this->service()->changePassword($this->user(), 'secret1', 'n3wSecret9', 'n3wSecret9');

            self::assertTrue($result);
            self::assertSame(
                ['revoke_all', 'set_password', 'clear_auth_cookie'],
                $this->events(),
                'old sessions die before the hash moves — never the other order'
            );

            $tokens = $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_auth_tokens'];
            self::assertCount(1, $tokens);
            self::assertSame(8, (int) $tokens[0]['user_id'], 'the sweep is per holder, other devices stay');
            self::assertSame('hashed:n3wSecret9', $GLOBALS['__aiya_test_users'][7]['user_pass']);
        }

        public function testFailedRevocationAbortsLeavingTheOldPassword(): void
        {
            global $wpdb;
            $wpdb = new RevocationFailsWpdb();

            $result = $this->service()->changePassword($this->user(), 'secret1', 'n3wSecret9', 'n3wSecret9');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_server_error', $result->get_error_code());
            self::assertSame(500, $result->get_error_data()['status']);
            self::assertSame([], $this->events(), 'no session revocation, so no password write and no cookie clear');
            self::assertSame(self::OLD_HASH, $GLOBALS['__aiya_test_users'][7]['user_pass'], 'the old password still applies');
        }

        // ------------------------------------------------------- registration

        public function testDuplicateEmailIsA409(): void
        {
            $GLOBALS['__aiya_test_account_emails'] = ['taken@aiya.test' => 31];

            $result = $this->service()->register('taken@aiya.test', 'n3wSecret9', 'n3wSecret9', 'Nick', null);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_email_exists', $result->get_error_code());
            self::assertSame(409, $result->get_error_data()['status'], 'the mailbox signal stays distinct for the registration UX');
            self::assertSame([], $this->events());
        }

        public function testWeakPasswordIsRefusedAtRegistration(): void
        {
            $result = $this->service()->register('fresh@aiya.test', 'short1', 'short1', 'Nick', null);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_invalid_password', $result->get_error_code());
            self::assertSame([], $this->events());
        }

        public function testMismatchedRegistrationConfirmationIsRefused(): void
        {
            $result = $this->service()->register('fresh@aiya.test', 'n3wSecret9', 'different9', 'Nick', null);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_invalid_password', $result->get_error_code());
            self::assertSame([], $this->events());
        }

        public function testRegistrationMintsTheLoginNameAndNotifies(): void
        {
            $userId = $this->service()->register('fresh@aiya.test', 'n3wSecret9', 'n3wSecret9', 'Nick', null);

            self::assertSame(55, $userId);
            self::assertSame('create_user', $this->events()[0][0]);
            self::assertSame('uuid-0001-0000-4000-8000', $this->events()[0][1], 'the login name is minted, never chosen or derived');
            self::assertSame('fresh@aiya.test', $this->events()[0][2]);
            self::assertSame('update_user', $this->events()[1][0]);
            self::assertSame(['ID' => 55, 'nickname' => 'Nick', 'display_name' => 'Nick'], $this->events()[1][1]);
            self::assertSame(['notify', 55, 'user'], $this->events()[2], 'the user leg rides the native chain');
        }

        public function testLocaleRidesTheProfileWrite(): void
        {
            $this->service()->register('fresh@aiya.test', 'n3wSecret9', 'n3wSecret9', 'Nick', 'zh_CN');

            self::assertSame('zh_CN', $this->events()[1][1]['locale']);
        }

        public function testUsernameCollisionRetriesToTheNextUuid(): void
        {
            $GLOBALS['__aiya_test_account_username_probe'] = static fn (string $username): int|false =>
                $username === 'uuid-0001-0000-4000-8000' ? 31 : false;

            $this->service()->register('fresh@aiya.test', 'n3wSecret9', 'n3wSecret9', 'Nick', null);

            self::assertSame('uuid-0002-0000-4000-8000', $this->events()[0][1], 'the mint retries against the users table');
        }

        public function testCollisionExhaustionRefuses(): void
        {
            $GLOBALS['__aiya_test_account_username_probe'] = static fn (string $username): int => 31;

            $result = $this->service()->register('fresh@aiya.test', 'n3wSecret9', 'n3wSecret9', 'Nick', null);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_registration_failed', $result->get_error_code());
            self::assertSame(500, $result->get_error_data()['status']);
            self::assertSame([], $this->events(), 'an account is never created on a contested login name');
        }

        public function testCreateUserFailureIsRegistrationFailed(): void
        {
            $GLOBALS['__aiya_test_account_create_user_error'] = true;

            $result = $this->service()->register('fresh@aiya.test', 'n3wSecret9', 'n3wSecret9', 'Nick', null);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_registration_failed', $result->get_error_code());
            self::assertCount(1, $this->events(), 'the profile write never runs after a failed creation');
            self::assertSame('create_user', $this->events()[0][0]);
        }

        public function testProfileWriteFailureAfterCreationIsRegistrationFailed(): void
        {
            $GLOBALS['__aiya_test_account_update_user_error'] = true;

            $result = $this->service()->register('fresh@aiya.test', 'n3wSecret9', 'n3wSecret9', 'Nick', null);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_registration_failed', $result->get_error_code());
            self::assertStringContainsString('profile could not be saved', $result->get_error_message());
            self::assertSame('create_user', $this->events()[0][0], 'the account row exists — a clean 200 must not be readable as fully registered');
            self::assertSame([], $this->events()[2] ?? [], 'the welcome notification never fires for a half-registered account');
        }
    }
}
