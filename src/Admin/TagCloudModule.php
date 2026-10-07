<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Shared\PublicTypes;

/**
 * The editor's tag picker (the tagcloud-link button under every tag box)
 * reads through core's `get-tagcloud` AJAX call, which hardcodes the query
 * to the 45 most-used terms — for a site with more tags than that, most of
 * the vocabulary is simply unpickable from the editor. This module widens
 * the query for the tag-level (flat) contract taxonomies: every term,
 * name-ordered, unused ones included. Hierarchical pickers (the category
 * boxes) keep their own core UI untouched.
 *
 * The widened content makes the button's core label ("Choose from the most
 * used tags") a lie, so the label is rewritten on the same taxonomies at
 * registration — our own textdomain carries the replacement.
 */
final class TagCloudModule implements Module
{
    public function register(): void
    {
        add_filter('get_terms_args', [$this, 'widenTagCloudQuery'], 10, 2);
        add_action('init', [$this, 'relabelPicker'], 20);
    }

    /**
     * Drops core's 45-term most-used window when the tag-cloud picker asks
     * for one of the flat vocabularies; every other read passes untouched.
     *
     * @param array<string, mixed> $args
     * @param list<string>         $taxonomies
     * @return array<string, mixed>
     */
    public function widenTagCloudQuery(array $args, array $taxonomies): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the action name is not a security decision; core verified assign_terms before this filter runs.
        if (($_POST['action'] ?? '') !== 'get-tagcloud') {
            return $args;
        }

        $flat = self::flatTaxonomies();
        foreach ($taxonomies as $taxonomy) {
            if (in_array((string) $taxonomy, $flat, true)) {
                unset($args['number']);
                $args['orderby'] = 'name';
                $args['order'] = 'ASC';
                $args['hide_empty'] = false;

                return $args;
            }
        }

        return $args;
    }

    /** Rewrites the picker button's label on the widened taxonomies. */
    public function relabelPicker(): void
    {
        foreach (self::flatTaxonomies() as $taxonomy) {
            $object = get_taxonomy($taxonomy);
            if ($object) {
                $object->labels->choose_from_most_used = __('Browse all tags', 'aiya-core');
            }
        }
    }

    /**
     * Every tag-level taxonomy across the public types (post_tag plus the
     * five resource vocabularies) — the hierarchical category boxes stay
     * out by contract.
     *
     * @return list<string>
     */
    private static function flatTaxonomies(): array
    {
        $all = [];
        foreach (PublicTypes::all() as $type) {
            foreach ($type->wpTagTaxonomies() as $taxonomy) {
                $all[$taxonomy] = true;
            }
        }

        return array_keys($all);
    }
}
