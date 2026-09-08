<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Membership;
use Aiya\Core\Api\Contract\Profile;
use Aiya\Core\Api\Contract\ProfileStats;
use Aiya\Core\Api\Contract\PostSummary;
use Aiya\Core\Domain\Content\PublicTypes;
use WP_Post;
use WP_Query;
use WP_User;

/**
 * Maps a user account to the public profile contract. This is the only
 * place the profile route touches WP_User; email and login name never
 * enter the projection. Sponsor validity reads the persistent protocol
 * meta (`sponsor_expiration` + `aya_force_cancel_sponsor`); favorites
 * read `favorite_posts` and resolve published posts only, in the saved
 * order.
 */
final class ProfilePresenter
{
    private const FAVORITES_LIMIT = 12;

    public function __construct(private PostPresenter $posts)
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
     * Published, non-protected posts among the saved favorites. The list
     * keeps the saved order capped at FAVORITES_LIMIT while `count`
     * reports the true number of published favorites (found rows are
     * exact whenever the page returned any row).
     *
     * @return array{count: int, posts: list<PostSummary>}
     */
    private function publishedFavorites(int $userId): array
    {
        $raw = get_user_meta($userId, 'favorite_posts', true);
        $ids = is_array($raw) ? array_values(array_filter(array_map('absint', $raw))) : [];
        if ($ids === []) {
            return ['count' => 0, 'posts' => []];
        }

        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'has_password' => false,
            'ignore_sticky_posts' => true,
            'post__in' => $ids,
            'orderby' => 'post__in',
            'posts_per_page' => min(count($ids), 100),
        ]);

        $out = [];
        foreach (is_array($query->posts) ? $query->posts : [] as $post) {
            if ($post instanceof WP_Post) {
                $postType = PublicTypes::get('post');
                $out[] = $this->posts->summary($post, $postType ?? PublicTypes::all()['post']);
            }
            if (count($out) === self::FAVORITES_LIMIT) {
                break;
            }
        }

        return ['count' => max(count($out), (int) $query->found_posts), 'posts' => $out];
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
