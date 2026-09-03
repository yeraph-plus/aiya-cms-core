<?php

declare(strict_types=1);

namespace Aiya\Core\Metadata\Storage;

use Aiya\Core\Settings\Storage\ValueStore;

final class PostMetaStore implements ValueStore
{
    public function __construct(private int $postId, private string $metaKey)
    {
    }

    public function all(): array
    {
        $value = get_post_meta($this->postId, $this->metaKey, true);
        return is_array($value) ? $value : [];
    }

    public function replace(array $values): void { update_post_meta($this->postId, $this->metaKey, $values); }
    public function delete(): void { delete_post_meta($this->postId, $this->metaKey); }
}
