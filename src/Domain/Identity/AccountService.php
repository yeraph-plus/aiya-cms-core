<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use WP_Error;

/**
 * The account-write invariants, owned by the domain instead of the REST
 * controllers: the email-change re-authentication gate, the
 * revoke-before-password-moves ordering (old tokens must never outlive
 * the change, and a failed sweep aborts while the old password still
 * applies), and registration with server-minted UUID login names. Any
 * future second writer (CLI, admin surface) routes through here, so the
 * orderings cannot drift apart between faces.
 */
final class AccountService
{
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly PasswordPolicy $policy,
    ) {
    }

    /**
     * The profile write behind the re-auth gate: when the payload moves
     * the email address, the current password must be presented first —
     * a stolen session must not silently take over the mailbox (and
     * through it the reset flow).
     *
     * @param array<string, mixed> $userdata The validated wp_users payload (ID included).
     */
    public function updateProfile(\WP_User $user, array $userdata, string $currentPassword): bool|WP_Error
    {
        $emailChanged = array_key_exists('user_email', $userdata)
            && $userdata['user_email'] !== $user->user_email;
        if ($emailChanged) {
            if ($currentPassword === '' || !wp_check_password($currentPassword, (string) $user->user_pass, (int) $user->ID)) {
                return new WP_Error('aiya_reauth_required', __('Changing the email address requires the current password.', 'aiya-core'), ['status' => 403]);
            }
        }

        // The payload always carries the ID key; write only when a real
        // field rides along (an ID-only array would fail wp_update_user's
        // empty-content path for nothing).
        $fields = array_diff_key($userdata, ['ID' => null]);
        if ($fields !== [] && is_wp_error(wp_update_user($userdata))) {
            return new WP_Error('aiya_update_failed', __('The profile could not be saved.', 'aiya-core'), ['status' => 500]);
        }

        return true;
    }

    /**
     * The password change: current-password proof, policy check, then the
     * ordering invariant — sweep every session BEFORE the password moves
     * (a failed sweep aborts with the old password still applying), set,
     * and clear the auth cookie.
     */
    public function changePassword(\WP_User $user, string $currentPassword, string $newPassword, string $passwordConfirm): bool|WP_Error
    {
        if (!wp_check_password($currentPassword, (string) $user->user_pass, (int) $user->ID)) {
            return new WP_Error('aiya_wrong_password', __('The current password is incorrect.', 'aiya-core'), ['status' => 400]);
        }

        $violations = $this->policy->validate($newPassword, $passwordConfirm);
        if ($violations !== []) {
            return new WP_Error('aiya_invalid_password', implode(' ', $violations), ['status' => 400]);
        }

        if (!$this->tokens->revokeAll((int) $user->ID)) {
            return new WP_Error('aiya_server_error', __('Existing sessions could not be invalidated; the password was left unchanged.', 'aiya-core'), ['status' => 500]);
        }

        wp_set_password($newPassword, (int) $user->ID);
        wp_clear_auth_cookie();

        return true;
    }

    /**
     * Front-end registration: login names are never chosen by users (a
     * UUID is minted here, retried against the user table), the mailbox
     * must be fresh (distinct 409 — registration UX needs the signal),
     * and the default new-user notification rides the native chain
     * (user leg only; the admin leg is gated off in MailModule).
     *
     * @return int|WP_Error The new user id.
     */
    public function register(string $email, string $password, string $passwordConfirm, string $nickname, ?string $locale): int|WP_Error
    {
        if (email_exists($email) !== false) {
            return new WP_Error('aiya_email_exists', __('This email address is already registered.', 'aiya-core'), ['status' => 409]);
        }

        $violations = $this->policy->validate($password, $passwordConfirm);
        if ($violations !== []) {
            return new WP_Error('aiya_invalid_password', implode(' ', $violations), ['status' => 400]);
        }

        $username = wp_generate_uuid4();
        $attempts = 0;
        while (username_exists($username) !== false && $attempts < 5) {
            $username = wp_generate_uuid4();
            ++$attempts;
        }
        if (username_exists($username) !== false) {
            return new WP_Error('aiya_registration_failed', __('The account could not be created, please retry.', 'aiya-core'), ['status' => 500]);
        }

        $userId = wp_create_user($username, $password, $email);
        if (is_wp_error($userId)) {
            return new WP_Error('aiya_registration_failed', __('The account could not be created, please retry.', 'aiya-core'), ['status' => 500]);
        }

        $userdata = [
            'ID' => $userId,
            'nickname' => $nickname,
            'display_name' => $nickname,
        ];
        if ($locale !== null) {
            $userdata['locale'] = $locale;
        }
        if (is_wp_error(wp_update_user($userdata))) {
            // The account row exists but the profile write failed — the
            // client must not read a clean 200 as "fully registered".
            return new WP_Error('aiya_registration_failed', __('The account was created, but the profile could not be saved.', 'aiya-core'), ['status' => 500]);
        }

        wp_send_new_user_notifications($userId, 'user');

        return $userId;
    }
}
