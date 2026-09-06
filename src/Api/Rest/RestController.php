<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Identity\AvatarModule;
use Aiya\Core\Domain\Identity\PasswordPolicy;
use Aiya\Core\Domain\Identity\PasswordResetService;
use Aiya\Core\Domain\Identity\TokenStore;

/**
 * Module owning the versioned headless API (`aiya/core/v1`): bearer-token
 * authentication plus the auth and self-service user controllers of the
 * user-domain batch. Content controllers land here with the M4/M5 slices.
 */
final class RestController implements Module
{
    public function __construct(private AvatarModule $avatars)
    {
    }

    public function register(): void
    {
        Envelope::register();

        $tokens = new TokenStore();
        $authentication = new TokenAuthentication($tokens);
        $authentication->register();

        add_action('rest_api_init', function () use ($tokens, $authentication): void {
            $presenter = new UserPresenter();
            $policy = new PasswordPolicy();

            (new AuthController(
                $tokens,
                new PasswordResetService(),
                $authentication,
                $policy,
                new RateLimiter(),
                $presenter
            ))->registerRoutes();

            (new UserController($presenter, $this->avatars, $tokens, $policy))->registerRoutes();
        });
    }
}
