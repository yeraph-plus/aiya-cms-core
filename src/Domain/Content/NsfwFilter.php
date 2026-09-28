<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Domain\Identity\ShowNsfw;

/**
 * Resolves the NSFW exclusion for one read. The configured term lists live
 * on the content-management settings page (one multi-select per public
 * type over its non-tag vocabularies); this service turns them into the
 * term taxonomy id list the query model drops from its rows.
 *
 * Deliberately the only NSFW-aware spot on the read path: ContentQuery
 * just receives an opaque "exclude these term taxonomy ids" list, and the
 * activation inputs — the front end's request flag and the viewer's
 * "always show NSFW" user meta — resolve here, at the API boundary, not
 * inside the query model.
 *
 * Resolution rules:
 *  - the flag off (default) excludes nothing — the filter only applies
 *    when a read explicitly asks for it, no session or capability needed;
 *  - a signed-in viewer with "always show NSFW content" (ShowNsfw) wins
 *    over the flag: their reads never exclude, whatever the front end
 *    sends (it cannot know better — the meta is server-side truth);
 *  - configured term ids that no longer exist simply drop out.
 */
final class NsfwFilter
{
    public function __construct()
    {
    }

    /**
     * The configured NSFW term ids (term_id) of one public type. This is
     * the list the /terms vocabulary withholding uses (terms are
     * identified by term_id there).
     *
     * @return list<int>
     */
    public function configuredTermIds(PublicType $type): array
    {
        $stored = aiya_core_opt('content', 'nsfw_' . $type->name, []);
        if (!is_array($stored)) {
            return [];
        }

        $ids = [];
        foreach ($stored as $id) {
            $int = is_scalar($id) ? (int) $id : 0;
            if ($int > 0) {
                $ids[] = $int;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The exclusion for a content read: term taxonomy ids to drop, empty
     * when the filter stays inactive for this viewer.
     *
     * @return list<int>
     */
    public function excludedTermTaxonomyIds(PublicType $type, bool $requested): array
    {
        if (!$requested) {
            return [];
        }

        $viewer = get_current_user_id();
        if ($viewer > 0 && ShowNsfw::always($viewer)) {
            return [];
        }

        return $this->resolveTaxonomyIds($type, $this->configuredTermIds($type));
    }

    /**
     * Whether the /terms vocabulary should withhold the NSFW terms of the
     * type — true unless the signed-in viewer's "always show" override is
     * set (the request flag itself is checked by the caller).
     */
    public function withholdsTerms(PublicType $type): bool
    {
        $viewer = get_current_user_id();

        return !($viewer > 0 && ShowNsfw::always($viewer));
    }

    /**
     * term_id → term_taxonomy_id, narrowed to the type's own vocabularies
     * (a configured id from another type's list, or a since-deleted term,
     * drops out). Resolved per candidate taxonomy because a bare
     * get_term() answers an ambiguous_term_id error when the same term_id
     * exists in more than one taxonomy. The controller resolves once per
     * type per request and hands the result to every query of that read
     * (main, sticky probe, recount), so no second cache layer sits on top.
     *
     * @param list<int> $termIds
     * @return list<int>
     */
    private function resolveTaxonomyIds(PublicType $type, array $termIds): array
    {
        $vocabularies = [];
        foreach ($type->taxonomies as [$wpTaxonomy, $contract]) {
            if ($contract === 'category') {
                $vocabularies[] = $wpTaxonomy;
            }
        }

        $ids = [];
        foreach ($termIds as $termId) {
            foreach ($vocabularies as $taxonomy) {
                $term = get_term($termId, $taxonomy);
                if ($term instanceof \WP_Term) {
                    $ttId = (int) $term->term_taxonomy_id;
                    if ($ttId > 0) {
                        $ids[] = $ttId;
                    }
                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
