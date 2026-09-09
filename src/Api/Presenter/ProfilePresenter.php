<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Membership;
use Aiya\Core\Api\Contract\Profile;
use Aiya\Core\Api\Contract\ProfileStats;
use Aiya\Core\Api\Contract\PostSummary;
use Aiya\Core\Domain\Content\PublicTypes;
use Aiya\Core\Domain\Identity\FavoriteService;
use WP_Post;
use WP_User;

/**
 * Maps a user account to the public profile contract. This is the only
 * place the profile route touches WP_User; email and login name never
 * enter the projection. Sponsor validity reads the persistent protocol
 * meta (`sponsor_expiration` + `aya_force_cancel_sponsor`); favorites
 * read the `aiya_user_favorites` relation table (0.28.0) and resolve
 * published posts only, newest favorite first.
 */
final class ProfilePresenter
{
    private const FAVORITES_LIMIT = 12;

    public function __construct(private PostPresenter $posts, private ?FavoriteService $favorites = null)
    {
    }

    public function present(WP_User $user): Profile
    {
        $favorites = $this->publishedFavorites((int) $user->ID);
		$expiration = (int) get_user_meta((int) $user->ID, 'sponsor_expiration', true);
        $forceCancel = (string) get_user_meta((int) $user->ID, 'aya_force_cancel_sponsor', true) === '1';
        $active = $expiration > time() && !$forceCancel;

        return new Profile(
            (int) $user->ID,
            (string) $user->user_nicename,
            (string) $user->display_name,
            $this->avatar($user),
            null,
            (string) get_user_meta((int) $user->ID, 'description', true),
            $this->joinedAt($user),
            new ProfileStats(
                0,
                $favorites['count'],
                (int) count_user_posts((int) $user->ID, 'post', true)
            ),
            new Membership(
                '',
                $active ? 'active' : 'inactive',
                $active ? (string) wp_date('c', $expiration) : null,
                []
            ),
            [],
            $favorites['posts']
        );
    }

    /**
     * Published favorites from the relation table, newest first, capped at
     * FAVORITES_LIMIT while `count` is the exact published-favorite total.
     *
     * @return array{count: int, posts: list<PostSummary>}
     */
    private function publishedFavorites(int $userId): array
    {
        $service = $this->favorites ?? new FavoriteService();
        $result = $service->published($userId, 1, self::FAVORITES_LIMIT);
        if ($result['ids'] === []) {
            return ['count' => 0, 'posts' => []];
        }

        $postType = PublicTypes::get('post') ?? PublicTypes::all()['post'];
        $out = [];
        foreach ($result['ids'] as $postId) {
            $post = get_post($postId);
            if ($post instanceof WP_Post) {
                $out[] = $this->posts->summary($post, $postType);
            }
        }

        return ['count' => $result['total'], 'posts' => $out];
    }

    private function avatar(WP_User $user): ?Image
    {
        $url = get_avatar_url((int) $user->ID, ['size' => 128]);
        if (!is_string($url) || $url === '') {
            return null;
        }

        return new Image($url, (string) $user->display_name, null, null);
    }

    private function joinedAt(WP_User $user): string
    {
        $iso = mysql2date('c', (string) $user->user_registered, false);

        return is_string($iso) ? $iso : '';
    }
}
