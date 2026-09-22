<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Presenter\CommentPresenter;
use Aiya\Core\Api\Presenter\FilePresenter;
use Aiya\Core\Api\Presenter\NotificationPresenter;
use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Api\Presenter\ProfilePresenter;
use Aiya\Core\Api\Presenter\SitePresenter;
use Aiya\Core\Api\Presenter\SmiliesPresenter;
use Aiya\Core\Api\Presenter\SponsorshipPresenter;
use Aiya\Core\Api\Presenter\UploadPresenter;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Api\Presenter\DiscussionPresenter;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\ContentQuery;
use Aiya\Core\Domain\Content\CommentQuery;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Content\RelatedPostsQuery;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Media\CardThumbnailService;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Engagement\CounterService;
use Aiya\Core\Domain\FileServe\DownloadService;
use Aiya\Core\Domain\FileServe\FileService;
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
        private FileService $files,
        private DownloadService $downloads,
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

        $smilies = new SmiliesRegistry();
        $smiliesRenderer = new SmiliesRenderer($smilies);

        add_action('rest_api_init', function () use ($tokens, $authentication, $smiliesRenderer, $smilies): void {
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

            (new CommentsController(new RateLimiter(), new CommentQuery($this->visibility), new CommentPresenter($smiliesRenderer)))->registerRoutes();

            (new UploadsController($this->processUpload, $this->paths, new RateLimiter(), new UploadPresenter()))->registerRoutes();

            (new ContentController(
                new ContentQuery($this->visibility),
                new RelatedPostsQuery($this->visibility),
                $postPresenter,
                new SitePresenter(),
                new ProfilePresenter($postPresenter, $favorites, $presenter, new FollowService()),
                new RateLimiter()
            ))->registerRoutes();

            (new SmiliesController($smilies, new SmiliesPresenter()))->registerRoutes();

            (new NotificationController(new NotificationService(), $presenter, new NotificationPresenter()))->registerRoutes();

            $ledger = new LedgerService();
            $entitlements = new EntitlementService($ledger);
            (new CreditController($ledger, new RedeemCodeService($entitlements), new RateLimiter()))->registerRoutes();

            // The membership domain is live again since the 0.50.0 tier
            // rewrite; Admin surfaces live under the membership menu.
            $membership = new MembershipService();
            $entitlements = new EntitlementService($ledger);
            (new SponsorshipController($membership, $entitlements, $ledger, new OrderService(), new RateLimiter(), new SponsorshipPresenter()))->registerRoutes();

            (new GatewayController(new OrderService(), $entitlements))->registerRoutes();

            $threads = new DiscussionService();
            (new DiscussionController($threads, new DiscussionPresenter($smiliesRenderer, $threads), new RateLimiter()))->registerRoutes();

            (new FileServeController($this->files, $this->downloads, new FilePresenter(), new RateLimiter()))->registerRoutes();
        });
    }
}
