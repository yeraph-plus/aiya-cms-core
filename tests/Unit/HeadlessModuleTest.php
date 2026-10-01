<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Infrastructure\Headless\HeadlessModule;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The revisions switch must hold for a fresh install (the field default
 * is "strip", so an unsaved option still disables) and for an explicitly
 * saved one, and turning it off must hand the incoming limit through
 * untouched so core's WP_POST_REVISIONS handling keeps ruling. Two gates
 * carry the promise: wp_revisions_to_keep → 0 rules save-time revisions
 * and the revisions browser (both read wp_revisions_enabled()), while
 * editor autosaves — whose write path never consults that gate (WP 7.1
 * wp-admin/includes/post.php) — are declined at wp_insert_post's
 * empty-content filter, revision rows only.
 */
final class HeadlessModuleTest extends TestCase
{
    private HeadlessModule $module;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];

        $this->module = new HeadlessModule(new Registry());
        $this->module->register();
    }

    public function testFreshInstallsStopStoringRevisions(): void
    {
        $this->module->apply();

        // Core passes the WP_POST_REVISIONS-resolved limit through; the
        // default-on toggle answers zero for every shape of it.
        self::assertSame(0, apply_filters('wp_revisions_to_keep', -1, null));
        self::assertSame(0, apply_filters('wp_revisions_to_keep', 5, null));
    }

    public function testASavedToggleKeepsWorking(): void
    {
        $GLOBALS['__aiya_test_options']['optimization']['disable_revisions'] = true;
        $this->module->apply();

        self::assertSame(0, apply_filters('wp_revisions_to_keep', -1, null));
    }

    public function testTurningTheToggleOffRestoresCoreBehaviour(): void
    {
        $GLOBALS['__aiya_test_options']['optimization']['disable_revisions'] = false;
        $this->module->apply();

        self::assertSame(-1, apply_filters('wp_revisions_to_keep', -1, null));
        self::assertSame(3, apply_filters('wp_revisions_to_keep', 3, null));
    }

    /**
     * The autosave gate: a revision-shaped insert is declined (the editor
     * autosave snapshot never lands), a real post insert passes through
     * with core's own emptiness verdict, and the toggle off registers no
     * gate at all.
     */
    public function testAutosaveSnapshotsAreDeclinedWhileRealPostsPassThrough(): void
    {
        $this->module->apply();

        self::assertTrue(
            apply_filters('wp_insert_post_empty_content', false, ['post_type' => 'revision']),
            'an autosave snapshot row is declined from storing'
        );
        self::assertFalse(
            apply_filters('wp_insert_post_empty_content', false, ['post_type' => 'post', 'post_content' => '']),
            'a real post insert keeps core\'s own emptiness verdict'
        );
    }

    public function testTheSwitchRegistersOnTheOptimizationPageWithStripDefault(): void
    {
        $registry = new Registry();
        (new HeadlessModule($registry))->settings();

        $fields = [];
        foreach ($registry->page('optimization')?->fields() ?? [] as $field) {
            $fields[$field->id()] = $field;
        }

        self::assertArrayHasKey('disable_revisions', $fields);
        self::assertSame('switch', $fields['disable_revisions']->type());
        self::assertTrue($fields['disable_revisions']->defaultValue());
    }
}
