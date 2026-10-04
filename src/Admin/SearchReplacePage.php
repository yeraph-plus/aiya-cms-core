<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Domain\Shared\PublicTypes;

/**
 * Search & replace across wp_posts text columns with raw SQL — a query
 * only preview first, then an explicit execute. Deliberately bypasses
 * wp_update_post: no hooks fire, no revisions are created, the modified
 * timestamps stay put, and meta/options (the serialized-data minefield)
 * are never touched. The match is byte-exact case-sensitive: LIKE BINARY
 * for the preview, SQL REPLACE() for the update — two semantics that
 * agree, so the preview count always equals what execution will do.
 *
 * The object cache is cleaned per affected id after execution so a
 * persistent cache cannot keep serving the old content.
 */
final class SearchReplacePage
{
    private const MENU_SUFFIX = 'aiya-core-devtools-search-replace';
    private const ACTION_EXECUTE = 'aiya_core_devtools_search_replace_execute';
    private const NONCE_PREVIEW = 'aiya_core_devtools_search_replace_preview';
    private const SAMPLE_LIMIT = 5;
    private const SNIPPET_PADDING = 60;

    /** Execute-pass window: posts per REPLACE statement. */
    private const REPLACE_BATCH = 500;

    /** Hard ceiling on posts touched by one execute pass (a runaway guard). */
    private const REPLACE_CEILING = 200000;

    private const COLUMNS = ['post_content', 'post_title', 'post_excerpt'];
    private const STATUSES_ALL = ['publish', 'draft', 'pending', 'future', 'private'];

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_EXECUTE, [$this, 'handleExecute']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run search and replace.', 'aiya-core'));
        }

        Ui::pageHead(
            __('Search & Replace', 'aiya-core'),
            __('Direct wp_posts search and replace: query first, then execute. Raw SQL — no hooks fire, no revisions, no modified-date bump, meta and options untouched. Back up the database before executing; the match is case-sensitive and cannot be undone.', 'aiya-core')
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counter
        $count = absint((string) ($_GET['aiya_devtools_count'] ?? '0'));
        Ui::flash('aiya_devtools_note', [
            'replace_done' => [
                sprintf(
                    /* translators: %d: number of updated rows */
                    __('Updated %d rows in the posts table.', 'aiya-core'),
                    $count
                ),
                'success',
            ],
            'replace_none' => [__('Nothing matched — nothing was updated.', 'aiya-core'), 'success'],
            'replace_missing_search' => [__('The search string is empty.', 'aiya-core'), 'success'],
        ]);
        $this->formCard();
        $this->previewSection();
        Ui::pageFoot();
    }

    /** The query form; submitting it re-renders the page with the preview. */
    private function formCard(): void
    {
        $search = $this->getInput('sr_search');
        $replace = $this->getInput('sr_replace');
        $columns = self::sanitizeColumns($_GET['sr_cols'] ?? []); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only form repopulation, the query itself is gated below
        $types = self::sanitizeTypes($_GET['sr_types'] ?? []); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above
        $status = sanitize_key($this->getInput('sr_status'));
        Ui::card(__('Search & replace', 'aiya-core'), static function () use ($search, $replace, $columns, $types, $status): void {
            ?>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(SearchReplacePage::MENU_SUFFIX); ?>">
                <?php wp_nonce_field(SearchReplacePage::NONCE_PREVIEW); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="aiya-devtools-sr-search"><?php esc_html_e('Search', 'aiya-core'); ?></label></th>
                        <td>
                            <input type="text" id="aiya-devtools-sr-search" name="sr_search" class="regular-text" required value="<?php echo esc_attr($search); ?>">
                            <span class="description"><?php esc_html_e('Case-sensitive, matched byte-exactly.', 'aiya-core'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiya-devtools-sr-replace"><?php esc_html_e('Replace with', 'aiya-core'); ?></label></th>
                        <td>
                            <input type="text" id="aiya-devtools-sr-replace" name="sr_replace" class="regular-text" value="<?php echo esc_attr($replace); ?>">
                            <span class="description"><?php esc_html_e('Leave empty to remove every occurrence of the search string.', 'aiya-core'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Columns', 'aiya-core'); ?></th>
                        <td>
                            <?php foreach (SearchReplacePage::COLUMNS as $column) : ?>
                                <label style="margin-right:12px;">
                                    <input type="checkbox" name="sr_cols[]" value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, $columns, true)); ?>>
                                    <?php echo esc_html((string) $column); ?>
                                </label>
                            <?php endforeach; ?>
                            <span class="description"><?php esc_html_e('At least one; defaults to post_content.', 'aiya-core'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Post types', 'aiya-core'); ?></th>
                        <td>
                            <?php foreach (PublicTypes::wpPostTypes() as $type) : ?>
                                <label style="margin-right:12px;">
                                    <input type="checkbox" name="sr_types[]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $types, true)); ?>>
                                    <?php echo esc_html($type); ?>
                                </label>
                            <?php endforeach; ?>
                            <span class="description"><?php esc_html_e('Empty selection means every public type.', 'aiya-core'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiya-devtools-sr-status"><?php esc_html_e('Statuses', 'aiya-core'); ?></label></th>
                        <td>
                            <select id="aiya-devtools-sr-status" name="sr_status">
                                <option value="publish" <?php selected($status, 'publish'); ?>><?php esc_html_e('Published only', 'aiya-core'); ?></option>
                                <option value="all" <?php selected($status, 'all'); ?>><?php esc_html_e('All real statuses (no trash, no auto-draft)', 'aiya-core'); ?></option>
                            </select>
                        </td>
                    </tr>
                </tbody></table>
                <p><button type="submit" class="button button-primary"><?php esc_html_e('Query only', 'aiya-core'); ?></button></p>
            </form>
            <?php
        }, true);
    }

    /** Per-column counts, sample rows, the statement preview and the execute form. */
    private function previewSection(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the preview is a read-only SELECT gated on its own nonce
        $search = $this->getInput('sr_search');
        if ($search === '') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), self::NONCE_PREVIEW)) {
            return;
        }

        $columns = self::sanitizeColumns($_GET['sr_cols'] ?? []); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above
        $types = self::sanitizeTypes($_GET['sr_types'] ?? []); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above
        $statuses = self::statusesFor(sanitize_key($this->getInput('sr_status')));
        $statusInput = $this->getInput('sr_status');
        $replace = $this->getInput('sr_replace');
        $like = '%' . self::escLike($search) . '%';

        global $wpdb;
        /** @var \wpdb $wpdb */

        $counts = [];
        $total = 0;
        foreach ($columns as $column) {
            [$where, $params] = self::buildWhere([$column], $types, $statuses, $like);
            // @phpstan-ignore argument.type (whitelist interpolation)
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}", $params)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- whitelist-built SQL, see buildWhere
            $counts[$column] = $count;
            $total += $count;
        }

        // Sample rows and the statement preview only exist for a non-empty
        // match set; the empty branch below prints its own note.
        $sampleRows = [];
        $updateSql = '';
        if ($total > 0) {
            [$where, $params] = self::buildWhere($columns, $types, $statuses, $like);
            // @phpstan-ignore argument.type (whitelist interpolation)
            $samples = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE {$where} ORDER BY ID DESC LIMIT " . self::SAMPLE_LIMIT, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- whitelist-built SQL, see buildWhere
            $sampleRows = is_array($samples) ? $samples : [];
            // The statement preview carries the sample ids only: the execute
            // pass runs in bounded batches (never one statement with every
            // matching id), so a full id sweep here would buy nothing but
            // memory pressure on large matches.
            $sampleIds = array_map(static fn ($row): int => (int) $row->ID, $sampleRows);
            [$updateSql] = self::buildUpdateSql($columns, $search, $replace, $sampleIds, $wpdb->posts);
        }

        Ui::card(sprintf(
            /* translators: %s: search string. */
            __('Preview for "%s"', 'aiya-core'),
            $search
        ), static function () use ($search, $columns, $types, $replace, $statusInput, $counts, $total, $sampleRows, $updateSql): void {
            if ($total === 0) {
                echo '<p><em>';
                esc_html_e('No matches in the selected columns, types and statuses.', 'aiya-core');
                echo '</em></p>';
                return;
            }

            echo '<table class="widefat striped" style="max-width:640px;"><tbody>';
            foreach ($counts as $column => $count) {
                printf(
                    '<tr><td><code>%s</code></td><td>%d</td></tr>',
                    esc_html((string) $column),
                    (int) $count
                );
            }
            echo '</tbody></table>';

            if ($sampleRows !== []) {
                Ui::heading(__('Sample matches (newest first)', 'aiya-core'), 3);
                echo '<table class="widefat striped"><tbody>';
            }
            foreach ($sampleRows as $row) {
                // The snippet walks content → excerpt → title so a title-only
                // match still shows its context.
                $snippet = self::snippet((string) $row->post_content, $search);
                if ($snippet === '') {
                    $snippet = self::snippet((string) $row->post_excerpt, $search);
                }
                if ($snippet === '') {
                    $snippet = self::snippet((string) $row->post_title, $search);
                }
                printf(
                    '<tr><td>#%d</td><td><strong>%s</strong></td><td>%s</td></tr>',
                    (int) $row->ID,
                    esc_html((string) $row->post_title),
                    $snippet // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- snippet escapes every fragment before assembly
                );
            }
            if ($sampleRows !== []) {
                echo '</tbody></table>';
            }

            Ui::heading(__('Statement', 'aiya-core'), 3);
            echo '<p><code>' . esc_html($updateSql) . '</code></p>';
            echo '<p class="description">'
                . esc_html(sprintf(
                    /* translators: %d: batch size. */
                    __('Execution runs this replace in batches of %d posts until no match remains; the ids above are a sample window.', 'aiya-core'),
                    self::REPLACE_BATCH
                ))
                . '</p>';
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_EXECUTE); ?>">
                <input type="hidden" name="sr_search" value="<?php echo esc_attr($search); ?>">
                <input type="hidden" name="sr_replace" value="<?php echo esc_attr($replace); ?>">
                <input type="hidden" name="sr_status" value="<?php echo esc_attr($statusInput); ?>">
                <?php foreach ($columns as $column) : ?>
                    <input type="hidden" name="sr_cols[]" value="<?php echo esc_attr($column); ?>">
                <?php endforeach; ?>
                <?php foreach ($types as $type) : ?>
                    <input type="hidden" name="sr_types[]" value="<?php echo esc_attr($type); ?>">
                <?php endforeach; ?>
                <?php wp_nonce_field(self::ACTION_EXECUTE); ?>
                <button type="submit" class="button button-primary" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Run the replace on the posts table now? This cannot be undone.', 'aiya-core'))); ?>);"><?php esc_html_e('Execute replace', 'aiya-core'); ?></button>
            </form>
            <?php
        }, true);
    }

    /** Collects the affected ids, runs the UPDATE and cleans the post caches. */
    public function handleExecute(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run search and replace.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_EXECUTE);

        $search = sanitize_text_field(wp_unslash((string) ($_POST['sr_search'] ?? '')));
        if ($search === '') {
            Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'replace_missing_search']);
        }

        // Byte-exact by contract, same as the preview: raw input, escaping
        // happens in SQL (prepare + escLike) only.
        $replace = wp_unslash((string) ($_POST['sr_replace'] ?? ''));
        $columns = self::sanitizeColumns(is_array($_POST['sr_cols'] ?? null) ? $_POST['sr_cols'] : []); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- elements re-validated by the whitelist in sanitizeColumns
        $types = self::sanitizeTypes(is_array($_POST['sr_types'] ?? null) ? $_POST['sr_types'] : []); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- see above
        $statuses = self::statusesFor(sanitize_key((string) ($_POST['sr_status'] ?? '')));
        $like = '%' . self::escLike($search) . '%';

        global $wpdb;
        /** @var \wpdb $wpdb */

        [$where, $params] = self::buildWhere($columns, $types, $statuses, $like);

        // Bounded batches: take a window of matching ids, replace (which
        // removes the search string, so the window's rows leave the match
        // set), repeat until the table stops matching. Memory stays O(batch)
        // and no statement ever carries the whole id list.
        $updated = 0;
        while ($updated < self::REPLACE_CEILING) {
            // @phpstan-ignore-next-line argument.type (whitelist interpolation)
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE {$where} LIMIT " . self::REPLACE_BATCH, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- whitelist-built SQL, see buildWhere
            $ids = array_map(static fn ($row): int => (int) $row->ID, is_array($rows) ? $rows : []);
            if ($ids === []) {
                break;
            }

            [$updateSql, $updateParams] = self::buildUpdateSql($columns, $search, $replace, $ids, $wpdb->posts);
            // @phpstan-ignore argument.type, argument.type (whitelist interpolation; prepare() answers string here)
            $affected = $wpdb->query($wpdb->prepare($updateSql, $updateParams)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- whitelist-built SQL, see buildUpdateSql
            foreach ($ids as $id) {
                clean_post_cache($id);
            }
            // The replace must consume its own window; a batch that touches
            // nothing would loop forever, so treat it as done.
            if (!is_int($affected) || $affected === 0) {
                break;
            }
            $updated += count($ids);
        }

        if ($updated === 0) {
            Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'replace_none']);
        }

        Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'replace_done', 'aiya_devtools_count' => (string) $updated]);
    }

    /** The page's own admin URL, the Ui::redirect base for every round trip. */
    private static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SUFFIX);
    }

    private function getInput(string $key): string
    {
        // Deliberately raw (wp_unslash only): the search/replace pair is
        // byte-exact by contract, and sanitize_text_field would strip tag
        // shapes and fold whitespace out of the needle before it ever
        // reaches the LIKE. SQL-side the values only travel through
        // prepare()+escLike; output-side every echo is esc_attr'd. Status
        // keys are sanitized by their callers.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return wp_unslash((string) ($_GET[$key] ?? ''));
    }

    /**
     * Whitelist for the target columns, in canonical order; an empty or
     * fully-invalid selection falls back to post_content.
     *
     * @param mixed $raw
     * @return list<'post_content'|'post_title'|'post_excerpt'>
     */
    public static function sanitizeColumns(mixed $raw): array
    {
        $picked = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (in_array((string) $value, self::COLUMNS, true)) {
                $picked[] = (string) $value;
            }
        }

        /** @var list<'post_content'|'post_title'|'post_excerpt'> $ordered */
        $ordered = array_values(array_intersect(self::COLUMNS, array_unique($picked)));

        return $ordered === [] ? ['post_content'] : $ordered;
    }

    /**
     * Whitelist for the public post types, in registry order; an empty or
     * fully-invalid selection falls back to every public type.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function sanitizeTypes(mixed $raw): array
    {
        $all = PublicTypes::wpPostTypes();
        $picked = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (in_array((string) $value, $all, true)) {
                $picked[] = (string) $value;
            }
        }

        /** @var list<string> $ordered */
        $ordered = array_values(array_intersect($all, array_unique($picked)));

        return $ordered === [] ? $all : $ordered;
    }

    /**
     * 'all' means every real content status; anything else is publish
     * only. Trash and auto-draft are never in scope.
     *
     * @return list<string>
     */
    public static function statusesFor(string $mode): array
    {
        return $mode === 'all' ? self::STATUSES_ALL : ['publish'];
    }

    /**
     * Escapes the LIKE wildcards so % and _ in the search string match
     * literally (same semantics as $wpdb->esc_like, as a pure function).
     */
    public static function escLike(string $raw): string
    {
        return addcslashes($raw, '_%\\');
    }

    /**
     * The shared WHERE: type/status whitelists are interpolated (they can
     * only contain registry values), the match is a LIKE BINARY group over
     * the selected columns with one placeholder each.
     *
     * @param list<'post_content'|'post_title'|'post_excerpt'> $columns
     * @param list<string> $types
     * @param list<string> $statuses
     * @return array{0: string, 1: list<string>}
     */
    public static function buildWhere(array $columns, array $types, array $statuses, string $like): array
    {
        $match = [];
        foreach ($columns as $column) {
            $match[] = "{$column} LIKE BINARY %s";
        }

        $where = sprintf(
            'post_type IN (%s) AND post_status IN (%s) AND (%s)',
            implode(',', array_map(static fn (string $type): string => "'" . $type . "'", $types)),
            implode(',', array_map(static fn (string $status): string => "'" . $status . "'", $statuses)),
            implode(' OR ', $match)
        );

        return [$where, array_fill(0, count($columns), $like)];
    }

    /**
     * The UPDATE: one REPLACE() per selected column, ids interpolated as
     * absints. The table arrives from the caller's $wpdb->posts so the
     * statement is honest under any prefix.
     *
     * @param list<'post_content'|'post_title'|'post_excerpt'> $columns
     * @param list<int> $ids
     * @return array{0: string, 1: list<int|string>}
     */
    public static function buildUpdateSql(array $columns, string $search, string $replace, array $ids, string $table = 'wp_posts'): array
    {
        $sets = [];
        $params = [];
        foreach ($columns as $column) {
            $sets[] = "{$column} = REPLACE({$column}, %s, %s)";
            $params[] = $search;
            $params[] = $replace;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $sets);
        if ($ids !== []) {
            $sql .= ' WHERE ID IN (' . implode(',', array_map('absint', $ids)) . ')';
        }

        return [$sql, $params];
    }

    /**
     * A window around the first byte-exact match with the match itself
     * highlighted; empty when the haystack does not contain the needle.
     * Every fragment passes esc_html before assembly, so the output is
     * safe to print without a kses pass (which would strip <mark>).
     */
    public static function snippet(string $haystack, string $needle, int $padding = self::SNIPPET_PADDING): string
    {
        if ($needle === '') {
            return '';
        }

        $position = mb_strpos($haystack, $needle);
        if ($position === false) {
            return '';
        }

        $length = mb_strlen($haystack);
        $needleLength = mb_strlen($needle);
        $start = max(0, $position - $padding);
        $end = min($length, $position + $needleLength + $padding);

        $before = mb_substr($haystack, (int) $start, (int) ($position - $start));
        $match = mb_substr($haystack, (int) $position, (int) $needleLength);
        $after = mb_substr($haystack, (int) ($position + $needleLength), (int) ($end - $position - $needleLength));

        return ($start > 0 ? '…' : '')
            . esc_html($before) . '<mark>' . esc_html($match) . '</mark>' . esc_html($after)
            . ($end < $length ? '…' : '');
    }
}
