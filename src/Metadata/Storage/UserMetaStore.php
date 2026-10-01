<?php

declare(strict_types=1);

namespace Aiya\Core\Metadata\Storage;

use Aiya\Core\Settings\Storage\ValueStore;

/**
 * The user meta access the metadata framework speaks: one key, one value.
 * Registered user fields store each field under its own meta key (the
 * field id IS the meta key), so the per-field read()/write() pair is the
 * working surface and the group-array all()/replace()/delete() stay for
 * the ValueStore shape every other settings store shares.
 */
final class UserMetaStore implements ValueStore
{
    public function __construct(private int $userId, private string $metaKey)
    {
    }

    /** The single value under the key, or '' when absent. */
    public function read(): mixed
    {
        return get_user_meta($this->userId, $this->metaKey, true);
    }

    public function write(mixed $value): void
    {
        // See PostMetaStore::replace(): callers hand over unslashed values;
        // the meta API expects slashed data and would strip real
        // backslashes otherwise.
        update_user_meta($this->userId, $this->metaKey, wp_slash($value));
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
