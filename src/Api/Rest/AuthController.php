<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\AuthSession;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Domain\Identity\PasswordPolicy;
use Aiya\Core\Domain\Identity\PasswordResetService;
use Aiya\Core\Domain\Identity\TokenStore;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Auth + registration routes for the headless front end
 * (`aiya/core/v1/auth/*`). Login is email-only; the login name is a
 * server-generated UUID that clients never choose or see as a field.
 * Sessions are opaque bearer tokens, no cookies.
 */
final class AuthController
{
    private const LOGIN_HITS = 20;
    private const LOGIN_WINDOW = 10 * MINUTE_IN_SECONDS;
    private const REGISTER_HITS = 5;
    private const REGISTER_WINDOW = HOUR_IN_SECONDS;
    private const RESET_HITS = 5;
    private const RESET_WINDOW = 15 * MINUTE_IN_SECONDS;

    public function __construct(
        private TokenStore $tokens,
        private PasswordResetService $resets,
        private TokenAuthentication $authentication,
        private PasswordPolicy $policy,
        private RateLimiter $rateLimiter,
        private UserPresenter $presenter,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/auth/register', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->register($request),
            'permission_callback' => function (): bool|WP_Error {
                if (is_user_logged_in()) {
                    return new WP_Error('aiya_already_logged_in', __('You are already logged in.', 'aiya-core'), ['status' => 403]);
                }

                return true;
            },
            'args' => [
                'nickname' => ['type' => 'string', 'required' => true],
                'email' => ['type' => 'string', 'required' => true, 'format' => 'email'],
                'password' => ['type' => 'string', 'required' => true],
                'passwordConfirm' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/auth/login', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->login($request),
            'permission_callback' => '__return_true',
            'args' => [
                'email' => ['type' => 'string', 'required' => true, 'format' => 'email'],
                'password' => ['type' => 'string', 'required' => true],
                'remember' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/auth/logout', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (): WP_REST_Response => $this->logout(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/auth/password-reset-request', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->requestPasswordReset($request),
            'permission_callback' => '__return_true',
            'args' => [
                'email' => ['type' => 'string', 'required' => true, 'format' => 'email'],
                'domain' => ['type' => 'string', 'required' => false],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/auth/password-reset/validate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->validatePasswordReset($request),
            'permission_callback' => '__return_true',
            'args' => [
                'login' => ['type' => 'string', 'required' => true],
                'key' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/auth/password-reset', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->resetPassword($request),
            'permission_callback' => '__return_true',
            'args' => [
                'login' => ['type' => 'string', 'required' => true],
                'key' => ['type' => 'string', 'required' => true],
                'password' => ['type' => 'string', 'required' => true],
                'passwordConfirm' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    private function register(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->rateLimiter->hit('register', self::REGISTER_HITS, self::REGISTER_WINDOW)) {
            return $this->rateLimited();
        }

        if (!get_option('users_can_register')) {
            return new WP_Error(
                'aiya_registration_disabled',
                __('Registration is currently disabled.', 'aiya-core'),
                ['status' => 403]
            );
        }

        $nickname = trim(sanitize_text_field((string) $request->get_param('nickname')));
        $email = sanitize_email((string) $request->get_param('email'));
        $password = (string) $request->get_param('password');
        $confirmation = (string) $request->get_param('passwordConfirm');

        if ($nickname === '' || mb_strlen($nickname) > 50) {
            return $this->invalidParam(__('The nickname must be 1-50 characters.', 'aiya-core'));
        }
        if (!is_email($email)) {
            return $this->invalidParam(__('The email address is not valid.', 'aiya-core'));
        }
        if (email_exists($email) !== false) {
            return new WP_Error('aiya_email_exists', __('This email address is already registered.', 'aiya-core'), ['status' => 409]);
        }
        $violations = $this->policy->validate($password, $confirmation);
        if ($violations !== []) {
            return new WP_Error('aiya_invalid_password', implode(' ', $violations), ['status' => 400]);
        }

        // Login names are never chosen by users: a UUID is minted here.
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

        wp_update_user([
            'ID' => $userId,
            'nickname' => $nickname,
            'display_name' => $nickname,
        ]);

        return $this->sessionResponse((int) $userId, true);
    }

    private function login(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->rateLimiter->hit('login', self::LOGIN_HITS, self::LOGIN_WINDOW)) {
            return $this->rateLimited();
        }

        $email = sanitize_email((string) $request->get_param('email'));
        $password = (string) $request->get_param('password');

        if ($email === '' || $password === '') {
            return $this->invalidParam(__('Please provide the email address and the password.', 'aiya-core'));
        }

        $user = wp_authenticate_email_password(null, $email, $password);
        if (!$user instanceof WP_User) {
            // One message for unknown email and wrong password alike.
            return new WP_Error(
                'aiya_invalid_credentials',
                __('The email address or the password is incorrect.', 'aiya-core'),
                ['status' => 401]
            );
        }

        // The boolean arg arrives as a real bool over JSON; coerce anyway.
        return $this->sessionResponse((int) $user->ID, (bool) $request->get_param('remember'));
    }

    private function logout(): WP_REST_Response
    {
        $token = $this->authentication->presentedToken();
        if ($token !== null) {
            $this->tokens->revoke($token);
        }
        wp_clear_auth_cookie();

        return new WP_REST_Response(['done' => true]);
    }

    private function requestPasswordReset(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->rateLimiter->hit('password-reset', self::RESET_HITS, self::RESET_WINDOW)) {
            return $this->rateLimited();
        }

        $email = sanitize_email((string) $request->get_param('email'));
        if (!is_email($email)) {
            return $this->invalidParam(__('The email address is not valid.', 'aiya-core'));
        }

        $user = get_user_by('email', $email);
        if ($user instanceof WP_User) {
            $sent = $this->resets->sendResetLink($user, (string) $request->get_param('domain'));
            if (is_wp_error($sent)) {
                return new WP_Error($sent->get_error_code(), $sent->get_error_message(), ['status' => 500]);
            }
        }

        // The answer never distinguishes known from unknown mailboxes.
        return new WP_REST_Response(['sent' => true]);
    }

    private function validatePasswordReset(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $payload = $this->resetPayload($request);
        if ($payload instanceof WP_Error) {
            return $payload;
        }

        return new WP_REST_Response(['valid' => true, 'login' => $payload->user_login]);
    }

    private function resetPassword(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $payload = $this->resetPayload($request);
        if ($payload instanceof WP_Error) {
            return $payload;
        }

        $password = (string) $request->get_param('password');
        $violations = $this->policy->validate($password, (string) $request->get_param('passwordConfirm'));
        if ($violations !== []) {
            return new WP_Error('aiya_invalid_password', implode(' ', $violations), ['status' => 400]);
        }

        // Sweep the sessions before the password moves: a failed sweep
        // aborts the reset while the old password still applies.
        if (!$this->tokens->revokeAll((int) $payload->ID)) {
            return new WP_Error('aiya_server_error', __('Existing sessions could not be invalidated; the password was left unchanged.', 'aiya-core'), ['status' => 500]);
        }

        reset_password($payload, $password);

        return new WP_REST_Response(['done' => true]);
    }

    /**
     * Shared login+key validation for the two reset routes.
     *
     * @return WP_User|WP_Error
     */
    private function resetPayload(WP_REST_Request $request): WP_User|WP_Error
    {
        $login = sanitize_user((string) $request->get_param('login'), true);
        $key = sanitize_text_field((string) $request->get_param('key'));
        if ($login === '' || $key === '') {
            return $this->invalidParam(__('The reset link is missing required parameters.', 'aiya-core'));
        }

        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user)) {
            return new WP_Error(
                'aiya_invalid_reset_key',
                __('This password reset link is invalid or has expired.', 'aiya-core'),
                ['status' => 400]
            );
        }

        return $user;
    }

    private function sessionResponse(int $userId, bool $remember): WP_Error|WP_REST_Response
    {
        $user = get_userdata($userId);
        if (!$user instanceof WP_User) {
            return new WP_Error('aiya_user_missing', __('The account could not be loaded.', 'aiya-core'), ['status' => 500]);
        }

        try {
            $token = $this->tokens->issue($userId, $remember);
        } catch (RuntimeException) {
            // Token persistence failed; answer with an envelope instead of
            // letting the exception escape the REST callback.
            return new WP_Error('aiya_server_error', __('The session could not be started.', 'aiya-core'), ['status' => 500]);
        }

        $session = new AuthSession($token->token, $token->expiresAt, $this->presenter->present($user));

        return new WP_REST_Response($session->toArray());
    }

    private function requireLoggedIn(): bool|WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]);
    }

    private function rateLimited(): WP_Error
    {
        return new WP_Error('aiya_rate_limited', __('Too many requests, please retry later.', 'aiya-core'), ['status' => 429]);
    }

    private function invalidParam(string $message): WP_Error
    {
        return new WP_Error('aiya_invalid_param', $message, ['status' => 400]);
    }
}
