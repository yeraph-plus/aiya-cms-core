<?php

declare(strict_types=1);

namespace Aiya\Core\Settings\Options;

use Aiya\Core\Settings\Schema\Field;

/**
 * Resolves lazy option sources for select/radio fields at render time
 * (sub_mode 'page'/'category' map to the posts/terms sources here).
 *
 * Nothing queries until a field with an options_source is actually
 * rendered. Sidebar sources are not offered.
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
        [$taxonomies, $terms] = $this->sourceTerms($source);
        if ($taxonomies === [] || $terms === []) {
            return [];
        }
        $prefix = count($taxonomies) > 1;
        $bySlug = ($source['value_field'] ?? 'id') === 'slug';

        $options = [];
        foreach ($terms as $term) {
            $label = $term->name;
            if ($prefix) {
                $singular = $this->singularName((string) $term->taxonomy);
                $label = ($singular !== '' ? $singular . ' / ' : '') . $label;
            }
            $options[$bySlug ? $term->slug : (int) $term->term_id] = $label;
        }

        return $options;
    }

    /**
     * Term options as flat rows carrying their taxonomy — for consumers
     * that must tell vocabularies apart at render time (the repeater's
     * per-type category filter). Labels stay bare: the rows are shown one
     * vocabulary at a time, and same-slug terms of different taxonomies
     * survive as separate rows instead of collapsing on a shared key.
     *
     * @return list<array{value: string, label: string, taxonomy: string}>
     */
    public function termRows(Field $field): array
    {
        $source = $field->optionsSource();
        if (($source['source'] ?? '') !== 'terms') {
            return [];
        }
        [, $terms] = $this->sourceTerms($source);
        if ($terms === []) {
            return [];
        }
        $bySlug = ($source['value_field'] ?? 'id') === 'slug';

        $rows = [];
        foreach ($terms as $term) {
            $rows[] = [
                'value' => $bySlug ? (string) $term->slug : (string) (int) $term->term_id,
                'label' => (string) $term->name,
                'taxonomy' => (string) $term->taxonomy,
            ];
        }

        return $rows;
    }

    /**
     * Shared term query: the source's existing taxonomies in listed order
     * and their terms merged (empty when nothing resolves).
     *
     * @param array<string, mixed> $source
     * @return array{0: list<string>, 1: list<\WP_Term>}
     */
    private function sourceTerms(array $source): array
    {
        $taxonomies = (array) ($source['taxonomy'] ?? '');
        $taxonomies = array_values(array_filter(array_map('strval', $taxonomies), static fn (string $tax): bool => $tax !== '' && taxonomy_exists($tax)));
        if ($taxonomies === []) {
            return [[], []];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomies,
            'hide_empty' => false,
            'number' => self::MAX_TERMS,
        ]);
        if (is_wp_error($terms) || $terms === []) {
            return [$taxonomies, []];
        }

        return [$taxonomies, array_values($terms)];
    }

    private function singularName(string $taxonomy): string
    {
        $tax = get_taxonomy($taxonomy);
        $singular = is_object($tax) && isset($tax->labels->singular_name)
            ? (string) $tax->labels->singular_name
            : '';

        return $singular;
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
