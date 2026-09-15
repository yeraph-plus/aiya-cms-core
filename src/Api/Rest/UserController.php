<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Api\Contract\PostSummary;
use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Domain\Content\PublicTypes;
use Aiya\Core\Domain\Identity\FavoriteService;
use Aiya\Core\Domain\Identity\FollowService;
use Aiya\Core\Domain\Identity\PasswordPolicy;
use Aiya\Core\Domain\Identity\TokenStore;
use Aiya\Core\Domain\Identity\AvatarModule;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Self-service routes for the authenticated user (`aiya/core/v1/users/me`):
 * profile read/update, favorites (relation table, 0.28.0), local avatar
 * upload/removal, password change. Every route requires a session (bearer
 * token or cookie).
 */
final class UserController
{
    private const ALLOWED_LOCALES = ['zh_CN', 'zh_TW', 'zh_HK', 'en_US'];
    private const MAX_NICKNAME_LENGTH = 50;

    public function __construct(
        private UserPresenter $presenter,
        private AvatarModule $avatars,
        private TokenStore $tokens,
        private PasswordPolicy $policy,
        private PostPresenter $postPresenter,
        private FavoriteService $favorites,
        private FollowService $follows,
        private RateLimiter $rateLimiter,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/users/me', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->me(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/favorites', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->listFavorites($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'default' => 12, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/favorites', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->addFavorite($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => ['postId' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/favorites/(?P<postId>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->removeFavorite($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => ['postId' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/following', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->listFollowing($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'default' => 12, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/followers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->listFollowers($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'default' => 12, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/following/(?P<userId>\d+)', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->followUser($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => ['userId' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/following/(?P<userId>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->unfollowUser($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => ['userId' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/following/(?P<userId>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->isFollowingUser($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => ['userId' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/profile', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->updateProfile($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
			'args' => [
				'nickname' => ['type' => 'string', 'required' => false],
				'description' => ['type' => 'string', 'required' => false],
				'url' => ['type' => 'string', 'required' => false],
				'email' => ['type' => 'string', 'required' => false, 'format' => 'email'],
				'locale' => ['type' => 'string', 'required' => false],
				'currentPassword' => ['type' => 'string', 'required' => false, 'maxLength' => 200],
			],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/avatar', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->uploadAvatar($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            // Image-pipeline work per hit — the same budget class as the
            // uploads route.
            // Rate limiting happens inside uploadAvatar().
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/avatar', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => fn (): WP_REST_Response => $this->removeAvatar(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/users/me/password', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->changePassword($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'currentPassword' => ['type' => 'string', 'required' => true, 'maxLength' => 200],
                'password' => ['type' => 'string', 'required' => true, 'maxLength' => 200],
                'passwordConfirm' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    private function me(): WP_REST_Response
    {
        return new WP_REST_Response($this->presenter->present($this->currentUser())->toArray());
    }

    /** The viewer's published favorites, newest first. */
    private function listFavorites(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $this->currentUser()->ID;
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('perPage');
        $result = $this->favorites->published($userId, $page, $perPage);

        $postType = PublicTypes::get('post') ?? PublicTypes::all()['post'];
        $items = [];
        foreach ($result['ids'] as $postId) {
            $post = get_post($postId);
            if ($post !== null) {
                $items[] = $this->postPresenter->summary($post, $postType)->toArray();
            }
        }

        return new WP_REST_Response([
            'data' => $items,
            'meta' => [
                'apiVersion' => Contract::VERSION,
                'requestId' => Envelope::meta()['requestId'],
                'pagination' => Pagination::fromCounts($page, $perPage, $result['total'])->toArray(),
            ],
        ]);
    }

    private function addFavorite(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) $this->currentUser()->ID;
        if (!$this->rateLimiter->hit('favorites_write', 30, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $added = $this->favorites->add($userId, (int) $request->get_param('postId'));
        if (is_wp_error($added)) {
            return $added;
        }

        return new WP_REST_Response(['favorited' => true]);
    }

    private function removeFavorite(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->favorites->remove((int) $this->currentUser()->ID, (int) $request->get_param('postId'))) {
            return new WP_Error('aiya_server_error', __('The favorite could not be removed.', 'aiya-core'), ['status' => 500]);
        }

        return new WP_REST_Response(['favorited' => false]);
    }

    private function listFollowing(WP_REST_Request $request): WP_REST_Response
    {
        return $this->followList('followingIds', (int) $request->get_param('page'), (int) $request->get_param('perPage'));
    }

    private function listFollowers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->followList('followerIds', (int) $request->get_param('page'), (int) $request->get_param('perPage'));
    }

    /** @param 'followingIds'|'followerIds' $method */
    private function followList(string $method, int $page, int $perPage): WP_REST_Response
    {
        $userId = (int) $this->currentUser()->ID;
        $result = $this->follows->{$method}($userId, $page, $perPage);

        $items = [];
        foreach ($result['ids'] as $id) {
            $items[] = $this->postPresenter->author($id)->toArray();
        }

        return new WP_REST_Response([
            'data' => $items,
            'meta' => [
                'apiVersion' => Contract::VERSION,
                'requestId' => Envelope::meta()['requestId'],
                'pagination' => Pagination::fromCounts($page, $perPage, $result['total'])->toArray(),
            ],
        ]);
    }

    private function followUser(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->rateLimiter->hit('follow', 30, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, please retry later.', 'aiya-core'), ['status' => 429]);
        }

        $me = (int) $this->currentUser()->ID;
        $result = $this->follows->follow($me, (int) $request->get_param('userId'));
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(['following' => true]);
    }

    private function unfollowUser(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $me = (int) $this->currentUser()->ID;
        if (!$this->follows->unfollow($me, (int) $request->get_param('userId'))) {
            return new WP_Error('aiya_server_error', __('The follow could not be removed.', 'aiya-core'), ['status' => 500]);
        }

        return new WP_REST_Response(['following' => false]);
    }

    private function isFollowingUser(WP_REST_Request $request): WP_REST_Response
    {
        $me = (int) $this->currentUser()->ID;
        $following = $this->follows->isFollowing($me, (int) $request->get_param('userId'));

        return new WP_REST_Response(['following' => $following]);
    }

    private function updateProfile(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $user = $this->currentUser();
        $userdata = ['ID' => (int) $user->ID];
        $errors = [];

        $nickname = $request->get_param('nickname');
        if ($nickname !== null) {
            $nickname = trim(sanitize_text_field((string) $nickname));
            if ($nickname === '' || mb_strlen($nickname) > self::MAX_NICKNAME_LENGTH) {
                $errors[] = __('The nickname must be 1-50 characters.', 'aiya-core');
            } else {
                // display_name follows so the new name is what gets shown.
                $userdata['nickname'] = $nickname;
                $userdata['display_name'] = $nickname;
            }
        }

        $description = $request->get_param('description');
        if ($description !== null) {
            $userdata['description'] = sanitize_textarea_field((string) $description);
        }

        $url = $request->get_param('url');
        if ($url !== null) {
            $userdata['user_url'] = esc_url_raw((string) $url);
        }

        $email = $request->get_param('email');
        $emailChanged = false;
        if ($email !== null) {
            $email = sanitize_email((string) $email);
            if (!is_email($email)) {
                $errors[] = __('The email address is not valid.', 'aiya-core');
            } elseif ($email !== $user->user_email && email_exists($email) !== false) {
                $errors[] = __('This email address is already registered.', 'aiya-core');
            } else {
                $userdata['user_email'] = $email;
                $emailChanged = $email !== $user->user_email;
            }
        }

        $locale = $request->get_param('locale');
        if ($locale !== null) {
            $locale = sanitize_text_field((string) $locale);
            if (!in_array($locale, self::ALLOWED_LOCALES, true)) {
                $errors[] = __('The locale is not supported.', 'aiya-core');
            } else {
                $userdata['locale'] = $locale;
            }
        }

        if ($errors !== []) {
            return new WP_Error('aiya_validation_failed', implode(' ', $errors), ['status' => 400]);
        }

        // A stolen session must not be able to silently take over the
        // mailbox (and through it the reset flow); re-authenticate.
        if ($emailChanged) {
            $current = (string) $request->get_param('currentPassword');
            if ($current === '' || !wp_check_password($current, (string) $user->user_pass, (int) $user->ID)) {
                return new WP_Error('aiya_reauth_required', __('Changing the email address requires the current password.', 'aiya-core'), ['status' => 403]);
            }
        }

        if (count($userdata) > 1 && is_wp_error(wp_update_user($userdata))) {
            return new WP_Error('aiya_update_failed', __('The profile could not be saved.', 'aiya-core'), ['status' => 500]);
        }

        // Re-read: the memoized current-user object predates the update.
        return new WP_REST_Response($this->presenter->present($this->freshUser())->toArray());
    }

    private function uploadAvatar(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->rateLimiter->hit('avatar_upload', 10, 3600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $files = $request->get_file_params();
        $file = $files['avatar'] ?? null;
        if (!is_array($file) || empty($file['tmp_name'])) {
            return new WP_Error('aiya_avatar_missing', __('No avatar image was uploaded.', 'aiya-core'), ['status' => 400]);
        }

        try {
            $this->avatars->storeUploadedAvatar((int) $this->currentUser()->ID, $file);
        } catch (RuntimeException $error) {
            return new WP_Error('aiya_avatar_rejected', $error->getMessage(), ['status' => 400]);
        }

        return new WP_REST_Response($this->presenter->present($this->freshUser())->toArray());
    }

    private function removeAvatar(): WP_REST_Response
    {
        $this->avatars->removeAvatar((int) $this->currentUser()->ID);

        return new WP_REST_Response($this->presenter->present($this->freshUser())->toArray());
    }

    private function changePassword(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        // The current-password check runs full-cost bcrypt per attempt; the
        // budget keeps a stolen session from grinding it.
        if (!$this->rateLimiter->hit('change_password', 10, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, please retry later.', 'aiya-core'), ['status' => 429]);
        }
        $user = $this->currentUser();

        $current = (string) $request->get_param('currentPassword');
        if (!wp_check_password($current, (string) $user->user_pass, (int) $user->ID)) {
            return new WP_Error('aiya_wrong_password', __('The current password is incorrect.', 'aiya-core'), ['status' => 400]);
        }

        $password = (string) $request->get_param('password');
        $violations = $this->policy->validate($password, (string) $request->get_param('passwordConfirm'));
        if ($violations !== []) {
            return new WP_Error('aiya_invalid_password', implode(' ', $violations), ['status' => 400]);
        }

        // Sweep the sessions before the password moves: if the sweep fails,
        // abort while the old password still applies. Old tokens must not
        // outlive the change.
        if (!$this->tokens->revokeAll((int) $user->ID)) {
            return new WP_Error('aiya_server_error', __('Existing sessions could not be invalidated; the password was left unchanged.', 'aiya-core'), ['status' => 500]);
        }

        wp_set_password($password, (int) $user->ID);
        wp_clear_auth_cookie();

        return new WP_REST_Response(['done' => true]);
    }

    private function currentUser(): WP_User
    {
        $user = wp_get_current_user();

        return $user instanceof WP_User ? $user : new WP_User();
    }

    /** Re-reads the user from storage; use after any write to user data. */
    private function freshUser(): WP_User
    {
        $user = get_userdata((int) $this->currentUser()->ID);

        return $user instanceof WP_User ? $user : new WP_User();
    }

    private function requireLoggedIn(): bool|WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]);
    }
}
