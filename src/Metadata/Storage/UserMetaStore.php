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

    public function replace(array $values): void { update_user_meta($this->userId, $this->metaKey, $values); }
    public function delete(): void { delete_user_meta($this->userId, $this->metaKey); }
}
