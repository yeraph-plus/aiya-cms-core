<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\AdminBarFrontendLink;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-shims.php';

/**
 * The site-name dropdown entry only exists while the frontend domain is
 * configured, sits beside the core "Visit Site" item, and opens the
 * front end in a new tab.
 */
final class AdminBarFrontendLinkTest extends TestCase
{
    private AdminBarFrontendLink $module;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $this->module = new AdminBarFrontendLink();
    }

    public function testAddsASiblingOfVisitSiteInTheSiteNameDropdown(): void
    {
        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = 'http://localhost:4321';
        $bar = new \WP_Admin_Bar();

        $this->module->addNode($bar);

        self::assertCount(1, $bar->nodes);
        $node = $bar->nodes[0];
        self::assertSame('aiya-frontend', $node['id']);
        self::assertSame('site-name', $node['parent']);
        self::assertSame('http://localhost:4321', $node['href']);
        self::assertSame('_blank', $node['meta']['target']);
        self::assertSame('View front end', (string) $node['title']);
    }

    public function testAddsNothingWithoutAConfiguredDomain(): void
    {
        $bar = new \WP_Admin_Bar();

        $this->module->addNode($bar);

        self::assertSame([], $bar->nodes);
    }

    public function testEscapesTheTooltipBeforeItReachesTheBar(): void
    {
        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = 'https://front.example';
        $bar = new \WP_Admin_Bar();

        $this->module->addNode($bar);

        // The meta title lands in an HTML attribute: quotes must not survive raw.
        self::assertStringNotContainsString('"', (string) $bar->nodes[0]['meta']['title']);
    }

    public function testRegistrationHooksTheToolbarAction(): void
    {
        $this->module->register();

        $GLOBALS['__aiya_test_options']['frontend']['frontend_domain'] = 'https://front.example';
        $bar = new \WP_Admin_Bar();
        do_action('admin_bar_menu', $bar);

        self::assertCount(1, $bar->nodes);
    }
}
