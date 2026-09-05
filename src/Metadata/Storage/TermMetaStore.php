<?php

declare(strict_types=1);

namespace Aiya\Core\Metadata\Storage;

use Aiya\Core\Settings\Storage\ValueStore;

final class TermMetaStore implements ValueStore
{
    public function __construct(private int $termId, private string $metaKey)
    {
    }

    public function all(): array
    {
        $value = get_term_meta($this->termId, $this->metaKey, true);
        return is_array($value) ? $value : [];
    }

    public function replace(array $values): void
    {
        // See PostMetaStore::replace(): the meta API expects slashed data.
        update_term_meta($this->termId, $this->metaKey, wp_slash($values));
    }
    public function delete(): void { delete_term_meta($this->termId, $this->metaKey); }
}
