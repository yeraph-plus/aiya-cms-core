<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use WP_Term;

/**
 * Moves terms between the contract taxonomies — the bulk "move to
 * another taxonomy" operation on the term list screens. A move re-uses
 * the target taxonomy's term when the slug (then the name) already
 * exists there, otherwise creates it carrying name, slug and
 * description over; every object holding the source term gains the
 * target term, and the source term is deleted afterwards, so the
 * vocabulary stays clean. Hierarchy survives when the parent moved
 * along (mapped by the created target terms), and collapses to
 * top-level when it did not.
 */
final class TermTaxonomyMover
{
    /** Every taxonomy the contract vocabularies cover, in registry order.
     *
     * @return list<string>
     */
    public static function taxonomies(): array
    {
        $out = [];
        foreach (PublicTypes::all() as $type) {
            foreach ($type->taxonomies as [$wpTaxonomy]) {
                if (!in_array($wpTaxonomy, $out, true)) {
                    $out[] = $wpTaxonomy;
                }
            }
        }

        return $out;
    }

    /** Move targets for one source taxonomy (every contract taxonomy but itself).
     *
     * @return list<string>
     */
    public static function targetOptions(string $sourceTaxonomy): array
    {
        return array_values(array_diff(self::taxonomies(), [$sourceTaxonomy]));
    }

    /**
     * Moves a batch of terms from one taxonomy into another.
     *
     * @param list<int> $ids
     * @return array{moved: int, skipped: int}
     */
    public function moveTerms(array $ids, string $sourceTaxonomy, string $targetTaxonomy): array
    {
        $skipped = count($ids);
        if ($sourceTaxonomy === '' || $targetTaxonomy === '' || !$this->authorized($sourceTaxonomy, $targetTaxonomy)) {
            return ['moved' => 0, 'skipped' => $skipped];
        }

        $terms = [];
        foreach ($ids as $id) {
            $term = get_term((int) $id, $sourceTaxonomy);
            if ($term instanceof WP_Term) {
                $terms[(int) $term->term_id] = $term;
            }
        }
        if ($terms === []) {
            return ['moved' => 0, 'skipped' => $skipped];
        }

        // Pass 1: materialize every term in the target taxonomy (reuse
        // by slug, then name, else create) and remember the mapping.
        $map = [];
        foreach ($terms as $term) {
            $targetId = $this->ensureTerm($term, $targetTaxonomy);
            if ($targetId !== null) {
                $map[(int) $term->term_id] = $targetId;
            }
        }

        // Pass 2: restore hierarchy when the parent moved along.
        foreach ($terms as $term) {
            $sourceParent = (int) $term->parent;
            if ($sourceParent > 0 && isset($map[$sourceParent], $map[(int) $term->term_id])) {
                wp_update_term($map[(int) $term->term_id], $targetTaxonomy, ['parent' => max(0, $map[$sourceParent])]);
            }
        }

        // Pass 3: pull every object of the source term over to the
        // target, then remove the source term (its relations die with it).
        $moved = 0;
        foreach ($terms as $term) {
            $targetId = $map[(int) $term->term_id] ?? null;
            if ($targetId === null) {
                continue;
            }
            $objectIds = get_objects_in_term((int) $term->term_taxonomy_id, $sourceTaxonomy);
            foreach (is_array($objectIds) ? $objectIds : [] as $objectId) {
                wp_set_object_terms((int) $objectId, [$targetId], $targetTaxonomy, true);
            }
            wp_delete_term((int) $term->term_id, (string) $sourceTaxonomy);
            ++$moved;
        }

        return ['moved' => $moved, 'skipped' => $skipped - $moved];
    }

    /** Both taxonomies must be contract taxonomies and manageable by the viewer. */
    private function authorized(string $sourceTaxonomy, string $targetTaxonomy): bool
    {
        if ($sourceTaxonomy === $targetTaxonomy
            || !taxonomy_exists($sourceTaxonomy)
            || !taxonomy_exists($targetTaxonomy)
            || !in_array($targetTaxonomy, self::taxonomies(), true)) {
            return false;
        }

        $source = get_taxonomy($sourceTaxonomy);
        $target = get_taxonomy($targetTaxonomy);

        return $source !== false && $target !== false
            && current_user_can($source->cap->manage_terms)
            && current_user_can($target->cap->manage_terms);
    }

    /**
     * The target taxonomy's term for the source term: existing by slug
     * or name, else freshly created. Null when creation failed — the
     * caller skips the term and counts it.
     */
    private function ensureTerm(WP_Term $term, string $targetTaxonomy): ?int
    {
        $existing = get_term_by('slug', (string) $term->slug, $targetTaxonomy);
        if (!$existing instanceof WP_Term) {
            $existing = get_term_by('name', (string) $term->name, $targetTaxonomy);
        }
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term((string) $term->name, $targetTaxonomy, [
            'slug' => (string) $term->slug,
            'description' => (string) $term->description,
        ]);
        if (is_array($created) && isset($created['term_id'])) {
            return (int) $created['term_id'];
        }

        $created = wp_insert_term((string) $term->name, $targetTaxonomy);

        return is_array($created) && isset($created['term_id']) ? (int) $created['term_id'] : null;
    }
}
