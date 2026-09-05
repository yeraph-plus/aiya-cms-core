<?php

declare(strict_types=1);

namespace Aiya\Core\Metadata\Storage;

use Aiya\Core\Settings\Storage\ValueStore;

final class UserMetaStore implements ValueStore
{
    public function __construct(private int $userId, private string $metaKey)
    {
    }

    public function all(): array
    {
        $value = get_user_meta($this->userId, $this->metaKey, true);
        return is_array($value) ? $value : [];
    }

    public function replace(array $values): void
    {
        // See PostMetaStore::replace(): the meta API expects slashed data.
        update_user_meta($this->userId, $this->metaKey, wp_slash($values));
    }
    public function delete(): void { delete_user_meta($this->userId, $this->metaKey); }
}
