<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

use Aiya\Core\Settings\Schema\Field;
use Aiya\Core\Settings\ValueNormalizer;
use WP_Error;

/**
 * The post's file meta: one JSON object, one key per data group, and every
 * key an auto-generated short id ("1", "2", …) so an editor creates groups by
 * picking an adapter rather than naming anything. Each group names its
 * `adapter` and carries that adapter's own fields plus the two common ones
 * every group has — a display title and the credits charged per file.
 *
 * One field set drives three things: the metabox renders it, this class
 * normalizes what an editor submitted, and the service reads what it stored.
 * Values are normalized through the settings schema's own normalizer, so a
 * group's stored shape is exactly its declared fields — unknown keys an old
 * version may have written are dropped, and an invalid value fails the save
 * rather than being silently discarded.
 */
final class Config
{
    public const META_KEY = 'aiya_core_fileserve';

    /**
     * The fields every group carries, on top of whatever its adapter declares.
     *
     * @return list<array<string, mixed>>
     */
    public static function commonFields(): array
    {
        return [
            [
                'id' => 'title',
                'type' => 'text',
                'label' => __('List title', 'aiya-core'),
                'description' => __('Shown above this list on the front end; leave it empty to show the adapter name instead.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'price',
                'type' => 'number',
                'label' => __('Credits per file', 'aiya-core'),
                'description' => __('Charged for every download from this list; 0 keeps it free.', 'aiya-core'),
                'default' => 0,
                'min' => 0,
                'step' => 1,
            ],
        ];
    }

    /**
     * One group's whole field set: the adapter's own fields in its order, then
     * the common two.
     *
     * @return list<array<string, mixed>>
     */
    public static function fieldsFor(Adapter $adapter): array
    {
        return array_merge($adapter->fields(), self::commonFields());
    }

    /**
     * The stored configuration of one post, already normalized.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public static function read(int $postId, AdapterRegistry $adapters): array
    {
        // The metabox stores one JSON string; a hand-written or instrument-
        // written meta may arrive already decoded, and parse() reads both.
        return self::parse(get_post_meta($postId, self::META_KEY, true), $adapters)['config'];
    }

    /**
     * Reads a submitted configuration (a JSON string or an already-decoded
     * array) into the canonical shape.
     *
     * Any error means the caller must not store the result: groups it could
     * not read are reported and left out, and the editor sees why.
     *
     * @return array{config: array<int|string, array<string, mixed>>, errors: list<string>}
     */
    public static function parse(mixed $raw, AdapterRegistry $adapters): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return ['config' => [], 'errors' => [__('The file configuration could not be read.', 'aiya-core')]];
        }

        $config = [];
        $errors = [];
        $normalizer = new ValueNormalizer();

        foreach ($decoded as $rawId => $group) {
            $id = self::id((string) $rawId);
            if ($id === '') {
                $errors[] = __('A data group key could not be read.', 'aiya-core');
                continue;
            }
            if (!is_array($group)) {
                /* translators: %s: data group id. */
                $errors[] = sprintf(__('Data group %s could not be read.', 'aiya-core'), $id);
                continue;
            }

            $adapter = $adapters->get((string) ($group['adapter'] ?? ''));
            if ($adapter === null) {
                /* translators: %s: data group id. */
                $errors[] = sprintf(__('Data group %s names an adapter that is not available.', 'aiya-core'), $id);
                continue;
            }

            /** @var list<Field> $fields */
            $fields = array_map(
                static fn (array $definition): Field => Field::fromArray($definition),
                self::fieldsFor($adapter)
            );
            $normalized = $normalizer->normalize($fields, $group);
            if ($normalized instanceof WP_Error) {
                /* translators: 1: data group id, 2: error message. */
                $errors[] = sprintf(__('Data group %1$s: %2$s', 'aiya-core'), $id, $normalized->get_error_message());
                continue;
            }

            $normalized['adapter'] = $adapter->id();
            $normalized['price'] = max(0, (int) ($normalized['price'] ?? 0));
            $config[$id] = $normalized;
        }

        return ['config' => $config, 'errors' => $errors];
    }

    /** @param array<int|string, array<string, mixed>> $config */
    public static function encode(array $config): string
    {
        if ($config === []) {
            return '';
        }

        return (string) wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The next free short id: one past the highest numeric key, so creating a
     * group needs no name from the editor and a deleted id is never reused.
     *
     * @param array<int|string, array<string, mixed>> $config
     */
    public static function nextId(array $config): string
    {
        $highest = 0;
        foreach (array_keys($config) as $key) {
            if (ctype_digit((string) $key)) {
                $highest = max($highest, (int) $key);
            }
        }

        return (string) ($highest + 1);
    }

    /** A submitted key reduced to something storable; '' when nothing is left of it. */
    private static function id(string $raw): string
    {
        $id = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $raw);

        return substr($id, 0, 16);
    }
}
