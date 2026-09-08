<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Api\Presenter\ProfilePresenter;
use Aiya\Core\Api\Presenter\SitePresenter;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Api\Presenter\DiscussionPresenter;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\ContentQuery;
use Aiya\Core\Domain\Content\MenuService;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Engagement\CounterService;
use Aiya\Core\Domain\Identity\AvatarModule;
use Aiya\Core\Domain\Identity\PasswordPolicy;
use Aiya\Core\Domain\Identity\PasswordResetService;
use Aiya\Core\Domain\Identity\TokenStore;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Notification\NotificationService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;

/**
 * Module owning the versioned headless API (`aiya/core/v1`): bearer-token
 * authentication, the auth and self-service user controllers, engagement
 * counters, and the public content read routes (site shell, primary
 * menu, terms, posts).
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

        $menus = new MenuService();
        $menus->register();

        add_action('rest_api_init', function () use ($tokens, $authentication, $menus): void {
            $presenter = new UserPresenter();
            $policy = new PasswordPolicy();
            $postPresenter = new PostPresenter(new MediaPaths());

            (new AuthController(
                $tokens,
                new PasswordResetService(),
                $authentication,
                $policy,
                new RateLimiter(),
                $presenter
            ))->registerRoutes();

            (new UserController($presenter, $this->avatars, $tokens, $policy))->registerRoutes();

            (new CounterController(new CounterService(), new RateLimiter()))->registerRoutes();

            (new CommentsController(new RateLimiter()))->registerRoutes();

            (new ContentController(
                new ContentQuery(),
                $postPresenter,
                new SitePresenter(),
                $menus,
                new ProfilePresenter($postPresenter)
            ))->registerRoutes();

            (new NotificationController(new NotificationService(), $presenter))->registerRoutes();

            $membership = new MembershipService();
            $orders = new OrderService($membership);
            (new SponsorshipController($membership, $orders, new RedeemCodeService($orders), new RateLimiter()))->registerRoutes();

            (new GatewayController($orders))->registerRoutes();

            $threads = new DiscussionService();
            (new DiscussionController($threads, new DiscussionPresenter(), new RateLimiter()))->registerRoutes();
        });
    }
}
