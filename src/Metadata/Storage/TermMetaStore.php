<?php

declare(strict_types=1);

namespace Aiya\Core\Metadata\Storage;

use Aiya\Core\Settings\Storage\ValueStore;

/**
 * The term meta access the metadata framework speaks: one key, one value.
 * Term boxes store each field under its own meta key (the term protocol
 * shape), so the per-field read()/write() pair is the working surface and
 * the group-array all()/replace()/delete() stay for the ValueStore shape
 * every other settings store shares.
 */
final class TermMetaStore implements ValueStore
{
    public function __construct(private int $termId, private string $metaKey)
    {
    }

    /** The single value under the key, or '' when absent. */
    public function read(): mixed
    {
        return get_term_meta($this->termId, $this->metaKey, true);
    }

    public function write(mixed $value): void
    {
        // See PostMetaStore::replace(): callers hand over unslashed values;
        // the meta API expects slashed data and would strip real
        // backslashes otherwise.
        update_term_meta($this->termId, $this->metaKey, wp_slash($value));
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
