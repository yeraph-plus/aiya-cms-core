<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;

/**
 * Editor-side smilies picker: a toolbar button next to the template-parts
 * inserter opening a wpdialogs grid of every registered pack, one cell per
 * `::code::` token with its image preview. Insertion stores the TOKEN
 * text (never the image) at the cursor — the backend renderer turns tokens
 * into `img.aiya-smilie` on the_content, so the stored content stays
 * portable plain text in both editor modes (TinyMCE and QuickTags).
 *
 * The scan leans on SmiliesRegistry's per-request memo; dialog data is
 * embedded as JSON on post screens only.
 */
final class SmiliesPicker implements Module
{
    public function __construct(private SmiliesRegistry $registry)
    {
    }

    public function register(): void
    {
        add_action('media_buttons', [$this, 'toolbarButton'], 30);
        add_action('admin_footer', [$this, 'dialogMarkup']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    /** The classic toolbar position, below the template-parts inserter. */
    public function toolbarButton(string $editorId = 'content'): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && $screen->base !== 'post') {
            return;
        }

        printf(
            '<button type="button" class="button aiya-smilies-open" data-editor="%s"><span class="dashicons dashicons-smiley" aria-hidden="true"></span> %s</button>',
            esc_attr($editorId),
            esc_html__('Smilies', 'aiya-core')
        );
    }

    /** One hidden dialog skeleton; the grid fills from the embedded data. */
    public function dialogMarkup(): void
    {
        global $pagenow;
        if ($pagenow !== 'post.php' && $pagenow !== 'post-new.php') {
            return;
        }

        $packs = $this->registry->packs();
        if ($packs === []) {
            return;
        }

        ?>
        <div id="aiya-smilies-dialog" class="hidden">
            <div class="aiya-smilies-grid" role="listbox" aria-label="<?php esc_attr_e('Smilies', 'aiya-core'); ?>">
                <?php foreach ($packs as $pack) : ?>
                    <div class="aiya-smilies-pack">
                        <h2 class="aiya-smilies-pack-title"><?php echo esc_html($pack['slug']); ?></h2>
                        <ul>
                            <?php foreach ($pack['items'] as $item) : ?>
                                <li>
                                    <button type="button" class="aiya-smilies-item" data-token="<?php echo esc_attr('::' . $item['code'] . '::'); ?>" title="<?php echo esc_attr($item['code']); ?>">
                                        <img src="<?php echo esc_url($item['url']); ?>" alt="<?php echo esc_attr($item['code']); ?>" loading="lazy" decoding="async">
                                        <code><?php echo esc_html($item['code']); ?></code>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public function assets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'post.php' && $hookSuffix !== 'post-new.php') {
            return;
        }
        if ($this->registry->packs() === []) {
            return;
        }

        wp_enqueue_script('wpdialogs');
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style(
            'aiya-smilies-picker',
            AIYA_CORE_URL . 'assets/css/smilies-picker.css',
            ['wp-jquery-ui-dialog'],
            AIYA_CORE_VERSION
        );
        wp_enqueue_script(
            'aiya-smilies-picker',
            AIYA_CORE_URL . 'assets/js/smilies-picker.js',
            ['wpdialogs', 'quicktags'],
            AIYA_CORE_VERSION,
            true
        );
    }
}
