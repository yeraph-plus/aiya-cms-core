<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Shared\FrontendDomain;
use WP_Admin_Bar;

/**
 * Site-name dropdown entry to the front-end site: a "View front end"
 * item beside the core "Visit Site" link, opening the Frontend page's
 * "frontend domain" in a new tab. Renders only while that domain is
 * configured — without it the front end IS this install, and the core
 * site-name link already goes there.
 */
final class AdminBarFrontendLink implements Module
{
    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'addNode'], 50);
    }

    /**
     * Priority 50 only matters relative to wp_admin_bar_site_menu (30):
     * running after it appends the entry at the dropdown's tail, right
     * below "Visit Site" (and below "Edit Site" on block themes).
     */
    public function addNode(WP_Admin_Bar $bar): void
    {
        $origin = FrontendDomain::origin();
        if ($origin === null) {
            return;
        }

        $bar->add_node([
            'id' => 'aiya-frontend',
            'parent' => 'site-name',
            'title' => esc_html__('View front end', 'aiya-core'),
            'href' => esc_url($origin),
            'meta' => [
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'title' => esc_attr(__('Open the front-end site in a new tab', 'aiya-core')),
            ],
        ]);
    }
}
