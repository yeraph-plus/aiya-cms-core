<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\AvatarImage;
use Aiya\Core\Api\Contract\UserProfile;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use WP_User;

/**
 * Maps WP_User rows to the user contract. This is the only place the user
 * domain touches WP internals; everything downstream consumes DTOs.
 *
 * The role field keeps the legacy front-end levels (administrator /
 * author / sponsor / subscriber); sponsor validity reads the persistent
 * `sponsor_expiration` and `aya_force_cancel_sponsor` protocol meta.
 */
final class UserPresenter
{
    public function __construct(private MembershipService $membership = new MembershipService())
    {
    }

    public function present(WP_User $user): UserProfile
    {
        return new UserProfile(
            (int) $user->ID,
            (string) $user->user_login,
            (string) $user->display_name,
            (string) $user->user_email,
            (string) $user->user_url,
            (string) get_user_meta((int) $user->ID, 'description', true),
            get_user_locale((int) $user->ID),
            $this->registeredAt($user),
            $this->role($user),
            $this->avatar((int) $user->ID)
        );
    }

    public function role(WP_User $user): string
    {
        if (user_can($user, 'edit_pages')) {
            return 'administrator';
        }
        if (user_can($user, 'publish_posts')) {
            return 'author';
        }
        if ($this->membership->isSponsor((int) $user->ID)) {
            return 'sponsor';
        }

        return 'subscriber';
    }

    private function avatar(int $userId): AvatarImage
    {
        $large = get_avatar_url($userId, ['size' => 128]);
        $small = get_avatar_url($userId, ['size' => 64]);

        return new AvatarImage(
            is_string($large) && $large !== '' ? $large : '',
            is_string($small) && $small !== '' ? $small : ''
        );
    }

    private function registeredAt(WP_User $user): string
    {
        $iso = mysql2date('c', (string) $user->user_registered, false);

        return is_string($iso) ? $iso : '';
    }
}
