<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Api\Presenter\ProfilePresenter;
use Aiya\Core\Api\Presenter\SitePresenter;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Api\Presenter\DiscussionPresenter;
use Aiya\Core\Api\Rest\SmiliesController;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\ContentQuery;
use Aiya\Core\Domain\Content\PrimaryMenu;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Content\RelatedPostsQuery;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Media\CardThumbnailService;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Engagement\CounterService;
use Aiya\Core\Domain\ExternalFiles\AttachmentService;
use Aiya\Core\Domain\Identity\AvatarModule;
use Aiya\Core\Domain\Identity\FavoriteService;
use Aiya\Core\Domain\Identity\FollowService;
use Aiya\Core\Domain\Identity\PasswordPolicy;
use Aiya\Core\Domain\Identity\PasswordResetService;
use Aiya\Core\Domain\Identity\TokenStore;
use Aiya\Core\Domain\Notification\NotificationService;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;
use Closure;

/**
 * Module owning the versioned headless API (`aiya/core/v1`): bearer-token
 * authentication, the auth and self-service user controllers, engagement
 * counters, and the public content read routes (site shell, primary
 * menu, terms, posts).
 */
final class RestController implements Module
{
    public function __construct(
        private AvatarModule $avatars,
        private ?AttachmentService $attachments,
        private CardThumbnailService $cards,
        private Closure $processUpload,
        private MediaPaths $paths,
        private PostVisibility $visibility,
    ) {
    }

    public function register(): void
    {
        Envelope::register();
        (new CorsHeaders(fn (): array => array_values(array_map('strval', (array) aiya_core_opt('security', 'rest_allowed_origins', [])))))->register();
        (new HttpCache())->register();

        $tokens = new TokenStore();
        $authentication = new TokenAuthentication($tokens);
        $authentication->register();

        $menus = new PrimaryMenu();
        $smilies = new SmiliesRegistry();
        $smiliesRenderer = new SmiliesRenderer($smilies);

        add_action('rest_api_init', function () use ($tokens, $authentication, $menus, $smiliesRenderer, $smilies): void {
            $presenter = new UserPresenter();
            $policy = new PasswordPolicy();
            $postPresenter = new PostPresenter($this->cards, $smiliesRenderer, $this->visibility);

            (new AuthController(
                $tokens,
                new PasswordResetService(),
                $authentication,
                $policy,
                new RateLimiter(),
                $presenter
            ))->registerRoutes();

            $favorites = new FavoriteService();
            (new UserController($presenter, $this->avatars, $tokens, $policy, $postPresenter, $favorites, new FollowService(), new RateLimiter()))->registerRoutes();

            (new CounterController(new CounterService(), new RateLimiter()))->registerRoutes();

            (new CommentsController(new RateLimiter(), $smiliesRenderer))->registerRoutes();

            (new UploadsController($this->processUpload, $this->paths, new RateLimiter()))->registerRoutes();

            (new ContentController(
                new ContentQuery($this->visibility),
                new RelatedPostsQuery($this->visibility),
                $postPresenter,
                new SitePresenter(),
                $menus,
                new ProfilePresenter($postPresenter, $favorites, $presenter, new FollowService()),
                new RateLimiter()
            ))->registerRoutes();

            (new SmiliesController($smilies))->registerRoutes();

            (new NotificationController(new NotificationService(), $presenter))->registerRoutes();

            $ledger = new LedgerService();
            $entitlements = new EntitlementService($ledger);
            (new CreditController($ledger, new RedeemCodeService($entitlements), new RateLimiter()))->registerRoutes();

            // The membership domain is live again since the 0.50.0 tier
            // rewrite; Admin surfaces live under the membership menu.
            $membership = new MembershipService();
            $entitlements = new EntitlementService($ledger);
            (new SponsorshipController($membership, $entitlements, $ledger, new RateLimiter()))->registerRoutes();

            (new GatewayController(new OrderService(), $entitlements))->registerRoutes();

            $threads = new DiscussionService();
            (new DiscussionController($threads, new DiscussionPresenter($smiliesRenderer), new RateLimiter()))->registerRoutes();

            if ($this->attachments !== null) {
                (new ResourceAttachmentsController($this->attachments))->registerRoutes();
            }
        });
    }
}
