<?php

declare(strict_types=1);

use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Identity\AccountService;
use Aiya\Core\Domain\Identity\PasswordPolicy;
use Aiya\Core\Domain\Identity\TokenStore;
use Aiya\Core\Domain\Operations\StatsRecorder;

// Runs in real WordPress via WP-CLI, separately from the unit shims.
if (!defined('WP_CLI') || !WP_CLI || !defined('AIYA_CORE_VERSION')) {
    throw new RuntimeException('An active local WordPress and WP-CLI are required.');
}
$mode = $args[0] ?? '';
$prefix = $args[1] ?? '';
if (!preg_match('/^aiya_it_[a-f0-9]{12}$/D', $prefix)
    || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Fixtures require a unique test prefix and a loopback site.');
}
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn (): WP_Error => new WP_Error('aiya_test_http', 'External HTTP disabled in the fixture process.'));

function aiya_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function aiya_it_cleanup(string $prefix): void
{
    foreach (['login', 'member'] as $gate) {
        $post = get_page_by_path($prefix . '-' . $gate, OBJECT, 'post');
        if ($post instanceof WP_Post) {
            aiya_it_assert(wp_delete_post((int) $post->ID, true) instanceof WP_Post, 'Post cleanup failed.');
        }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ([$prefix, $prefix . '_viewer'] as $login) {
        $user = get_user_by('login', $login);
        if ($user instanceof WP_User) {
            aiya_it_assert((new TokenStore())->revokeAll((int) $user->ID), 'Token cleanup failed.');
            aiya_it_assert((new StatsRecorder())->deleteAccountData((int) $user->ID), 'Activity cleanup failed.');
            aiya_it_assert(wp_delete_user((int) $user->ID), 'User cleanup failed.');
        }
    }
}

/** Real MariaDB errors, injected only into the selected holder's statements. */
function aiya_it_with_sql_failure(int $userId, string $phase, Closure $operation): mixed
{
    global $wpdb;
    $hits = 0;
    $inject = static function (string $sql) use ($userId, $phase, &$hits, $wpdb): string {
        $matches = match ($phase) {
            'delete' => str_starts_with($sql, 'DELETE FROM ') && str_contains($sql, 'aiya_auth_tokens')
                && preg_match('/WHERE user_id = ' . $userId . '$/D', $sql) === 1,
            'generation' => str_starts_with($sql, 'UPDATE ') && str_contains($sql, 'aiya_core_auth_gen')
                && str_contains($sql, 'user_id = ' . $userId . ' '),
            'seed' => str_starts_with($sql, 'INSERT INTO ') && str_contains($sql, 'aiya_core_auth_gen'),
            default => false,
        };
        if (!$matches) {
            return $sql;
        }
        ++$hits;

        return (string) $wpdb->prepare('DELETE FROM %i', $wpdb->prefix . 'aiya_it_missing_' . $userId);
    };
    $previous = $wpdb->suppress_errors(true);
    add_filter('query', $inject);
    try {
        $result = $operation();
        aiya_it_assert($hits > 0, 'SQL fault was not reached: ' . $phase);

        return $result;
    } finally {
        remove_filter('query', $inject);
        $wpdb->suppress_errors($previous);
    }
}

function aiya_it_sql_regressions(WP_User $user, string $password): void
{
    $id = (int) $user->ID;
    $tokens = new TokenStore();
    $token = $tokens->issue($id)->token;
    aiya_it_assert($tokens->resolve($token) === $id, 'Token mirror must be warm.');
    $result = aiya_it_with_sql_failure($id, 'delete', static fn (): bool => $tokens->revokeAll($id));
    aiya_it_assert($result === false, 'Failed DELETE reported successful revocation.');
    aiya_it_assert($tokens->resolve($token) === $id, 'Failed DELETE must preserve the token.');
    $service = new AccountService($tokens, new PasswordPolicy());
    $result = aiya_it_with_sql_failure($id, 'delete', static fn (): bool|WP_Error =>
        $service->changePassword($user, $password, 'New.Aiya.Integration.2026!', 'New.Aiya.Integration.2026!'));
    aiya_it_assert(is_wp_error($result) && $result->get_error_code() === 'aiya_server_error', 'Password change must expose revocation failure.');
    clean_user_cache($id);
    aiya_it_assert(wp_check_password($password, get_userdata($id)->user_pass, $id), 'Password changed despite failed revocation.');
    update_user_meta($id, 'aiya_core_auth_gen', 1);
    $result = aiya_it_with_sql_failure($id, 'generation', static fn (): bool => $tokens->revokeAll($id));
    aiya_it_assert($result === false, 'Failed generation UPDATE reported success.');
    delete_user_meta($id, 'aiya_core_auth_gen');
    $result = aiya_it_with_sql_failure($id, 'seed', static fn (): bool => $tokens->revokeAll($id));
    aiya_it_assert($result === false, 'Failed generation INSERT reported success.');
    $token = $tokens->issue($id)->token;
    aiya_it_assert($tokens->resolve($token) === $id, 'Success-path mirror must be warm.');
    aiya_it_assert($tokens->revokeAll($id), 'Real revocation failed.');
    aiya_it_assert($tokens->resolve($token) === 0, 'Warm mirror accepted a revoked token.');
}

if ($mode === 'cleanup') {
    aiya_it_cleanup($prefix);
    WP_CLI::line('AIYA_INTEGRATION=' . wp_json_encode(['cleaned' => true]));

    return;
}
aiya_it_assert($mode === 'prepare', 'Unknown integration command.');
aiya_it_assert(get_user_by('login', $prefix) === false, 'Fixture prefix is already in use.');
$password = 'Aiya.Integration.2026!';
try {
    $id = wp_insert_user(['user_login' => $prefix, 'user_email' => $prefix . '@example.invalid',
        'user_pass' => $password, 'display_name' => $prefix, 'role' => 'administrator']);
    aiya_it_assert(!is_wp_error($id), 'Could not create privileged fixture.');
    $viewerId = wp_insert_user(['user_login' => $prefix . '_viewer', 'user_email' => $prefix . '_viewer@example.invalid',
        'user_pass' => $password, 'role' => 'subscriber']);
    aiya_it_assert(!is_wp_error($viewerId), 'Could not create non-member fixture.');
    aiya_it_sql_regressions(get_userdata($id), $password);
    $perPage = min(100, max(1, (int) get_option('comments_per_page', 50)));
    $posts = [];
    foreach (['login', 'member'] as $gate) {
        // Draft insertion avoids Telegram/follower publication and image jobs.
        $postId = wp_insert_post(['post_author' => $id, 'post_title' => $prefix . '-' . $gate,
            'post_name' => $prefix . '-' . $gate, 'post_status' => 'draft',
            'post_content' => '<p>Integration fixture.</p>', 'comment_status' => 'open'], true);
        aiya_it_assert(!is_wp_error($postId), 'Could not create fixture post.');
        global $wpdb;
        aiya_it_assert($wpdb->update($wpdb->posts, ['post_status' => 'publish', 'post_name' => $prefix . '-' . $gate],
            ['ID' => $postId]) !== false, 'Could not publish fixture row.');
        update_post_meta($postId, PostVisibility::META_KEY, $gate);
        clean_post_cache($postId);
        $commentIds = [];
        for ($index = 1; $index <= $perPage + 1; ++$index) {
            $commentIds[] = wp_insert_comment(['comment_post_ID' => $postId, 'comment_author' => 'Integration',
                'comment_content' => $prefix . '-' . $gate . '-comment-' . $index, 'comment_approved' => '1',
                'comment_date' => gmdate('Y-m-d H:i:s', time() - 300 + $index),
                'comment_date_gmt' => gmdate('Y-m-d H:i:s', time() - 300 + $index)]);
        }
        aiya_it_assert(!in_array(false, $commentIds, true), 'Approved comment insertion failed.');
        aiya_it_assert(wp_insert_comment(['comment_post_ID' => $postId, 'comment_author' => 'Held integration',
            'comment_content' => $prefix . '-held-must-not-leak', 'comment_approved' => '0']) !== false, 'Held comment insertion failed.');
        $posts[$gate] = ['id' => $postId, 'slug' => get_post_field('post_name', $postId), 'comments' => $commentIds];
    }
    $tokens = new TokenStore();
    WP_CLI::line('AIYA_INTEGRATION=' . wp_json_encode(['prefix' => $prefix, 'userId' => $id,
        'email' => $prefix . '@example.invalid', 'password' => $password, 'perPage' => $perPage, 'posts' => $posts,
        'token' => $tokens->issue($id)->token, 'viewerToken' => $tokens->issue($viewerId)->token]));
} catch (Throwable $error) {
    aiya_it_cleanup($prefix);
    throw $error;
}
