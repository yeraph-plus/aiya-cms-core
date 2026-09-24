<?php

declare(strict_types=1);

namespace Aiya\Core\Settings\Options;

use Aiya\Core\Settings\Schema\Field;

/**
 * Resolves lazy option sources for select/radio fields at render time —
 * the code-only equivalent of the legacy entry_select() sub_mode helper
 * (sub_mode 'page'/'category' map to the posts/terms sources here).
 *
 * Nothing queries until a field with an options_source is actually
 * rendered. Sidebar sources are not offered: sidebars belong to the
 * retired front end.
 */
final class OptionsResolver
{
    private const MAX_POSTS = 100;
    private const MAX_TERMS = 200;

    /** @return array<string|int, string> Option values mapped to labels. */
    public function resolve(Field $field): array
    {
        $source = $field->optionsSource();
        if ($source === []) {
            return [];
        }

        return match ((string) ($source['source'] ?? '')) {
            'terms' => $this->terms($source),
            'posts' => $this->posts($source),
            'users' => $this->users(),
            'option_list' => $this->optionList($source),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int|string, string>
     */
    private function terms(array $source): array
    {
        // A single taxonomy keeps bare term names; a list merges several
        // vocabularies into one option set and prefixes each label with
        // the taxonomy's singular name so same-named terms stay apart.
        $taxonomies = (array) ($source['taxonomy'] ?? '');
        $taxonomies = array_values(array_filter(array_map('strval', $taxonomies), static fn (string $tax): bool => $tax !== '' && taxonomy_exists($tax)));
        if ($taxonomies === []) {
            return [];
        }
        $prefix = count($taxonomies) > 1;

        $terms = get_terms([
            'taxonomy' => $taxonomies,
            'hide_empty' => false,
            'number' => self::MAX_TERMS,
        ]);
        if (is_wp_error($terms) || $terms === []) {
            return [];
        }

        $bySlug = ($source['value_field'] ?? 'id') === 'slug';
        $options = [];
        foreach ($terms as $term) {
            $label = $term->name;
            if ($prefix) {
                $tax = get_taxonomy((string) $term->taxonomy);
                $singular = is_object($tax) && isset($tax->labels->singular_name)
                    ? (string) $tax->labels->singular_name
                    : '';
                $label = ($singular !== '' ? $singular . ' / ' : '') . $label;
            }
            $options[$bySlug ? $term->slug : (int) $term->term_id] = $label;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int|string, string>
     */
    private function posts(array $source): array
    {
        $postType = (string) ($source['post_type'] ?? 'post');
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_POSTS,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        if ($posts === []) {
            return [];
        }

        $options = [];
        foreach ($posts as $post) {
            $options[(int) $post->ID] = $post->post_title;
        }

        return $options;
    }

    /** @return array<int, string> */
    private function users(): array
    {
        $users = get_users([
            'fields' => ['ID', 'user_login'],
            'orderby' => 'login',
        ]);

        $options = [];
        foreach ($users as $user) {
            $options[(int) $user->ID] = $user->user_login;
        }

        return $options;
    }

    /**
     * Reads a row list stored inside another settings option (e.g. the
     * membership tier repeater) and maps each row's fields into select
     * options — value from `value_field`, label from `label_field`.
     *
     * @param array<string, mixed> $source
     * @return array<string, string>
     */
    private function optionList(array $source): array
    {
        $optionName = (string) ($source['option'] ?? '');
        $listKey = (string) ($source['list'] ?? '');
        $valueField = (string) ($source['value_field'] ?? 'key');
        $labelField = (string) ($source['label_field'] ?? $valueField);
        if ($optionName === '' || $listKey === '') {
            return [];
        }

        $stored = (array) get_option($optionName, []);
        $rows = (array) ($stored[$listKey] ?? []);

        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = (string) ($row[$valueField] ?? '');
            if ($value === '') {
                continue;
            }
            $options[$value] = (string) ($row[$labelField] ?? $value);
        }

        return $options;
    }
}
