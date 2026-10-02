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

    /**
     * The namespace match is the redirect-vs-strip hinge: a first-party
     * target keeps the request on the API (public for every session), a
     * locked target lets a signed-in below-gate session bounce to the front
     * end. Both request shapes fold to the same leading-slash path.
     */
    public function testTheNamespaceMatchFoldsBothRequestShapes(): void
    {
        $allowed = ['/aiya/core/v1'];

        self::assertTrue(HeadlessModule::requestHitsNamespace($allowed, '/wp-json/aiya/core/v1/content/9'));
        self::assertTrue(
            HeadlessModule::requestHitsNamespace($allowed, '', '/aiya/core/v1/content/9'),
            'the plain-permalink REST form carries the route in the query'
        );
        self::assertTrue(
            HeadlessModule::requestHitsNamespace($allowed, '/blog/wp-json/aiya/core/v1/site'),
            'a sub-directory install is folded away at the REST prefix'
        );
        self::assertTrue(HeadlessModule::requestHitsNamespace(['/aiya-publish/v1'], '/wp-json/aiya-publish/v1/users'));

        self::assertFalse(HeadlessModule::requestHitsNamespace($allowed, '/wp-json/wp/v2/posts'));
        self::assertFalse(
            HeadlessModule::requestHitsNamespace($allowed, '', '/wp/v2/users'),
            'the query form answers for locked namespaces too'
        );
        self::assertFalse(
            HeadlessModule::requestHitsNamespace($allowed, '/wp-json/aiya/core/vX/content'),
            'the match is the full namespace, not a loose prefix'
        );
        self::assertFalse(
            HeadlessModule::requestHitsNamespace($allowed, '/wp-json/'),
            'the bare index belongs to nobody'
        );
        self::assertFalse(HeadlessModule::requestHitsNamespace($allowed, '/wp-json'));
        self::assertFalse(HeadlessModule::requestHitsNamespace($allowed, ''));
    }

    /**
     * The lock reads the Security page's back-end minimum role: while the
     * gate is off the author-level posture holds (author+ keeps /wp/v2,
     * everyone else keeps only the first-party routes), and raising the
     * gate to contributor hands contributor sessions the full API. The
     * redirect branch (signed-in below the gate on a locked target) exits,
     * so these fixtures keep the session anonymous or on a first-party
     * target — the pure decision above covers the bounce itself.
     */
    public function testTheLockFollowsTheUnifiedBackendGate(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $endpoints = ['/wp/v2/posts' => [], '/aiya/core/v1/content' => []];

        // Gate off: the fallback posture is author-level, anonymous keeps
        // the first-party routes only.
        $GLOBALS['__aiya_test_options']['security']['admin_backend_min_role'] = 'off';
        $GLOBALS['__aiya_test_caps'] = false;
        $locked = $this->module->lockWpV2($endpoints);
        self::assertArrayNotHasKey('/wp/v2/posts', $locked);
        self::assertArrayHasKey('/aiya/core/v1/content', $locked);

        $GLOBALS['__aiya_test_caps'] = true;
        self::assertArrayHasKey(
            '/wp/v2/posts',
            $this->module->lockWpV2($endpoints),
            'an author-level session clears the off-gate fallback'
        );

        // Gate at contributor: a session below it stays stripped even signed
        // in — the first-party target keeps the request off the redirect
        // branch, which is exactly the shape a front-end call carries.
        $GLOBALS['__aiya_test_options']['security']['admin_backend_min_role'] = 'contributor';
        $GLOBALS['__aiya_test_caps'] = false;
        $GLOBALS['__aiya_test_current_user_id'] = 7;
        $_SERVER['REQUEST_URI'] = '/wp-json/aiya/core/v1/content';
        try {
            $locked = $this->module->lockWpV2($endpoints);
        } finally {
            unset($_SERVER['REQUEST_URI']);
            $GLOBALS['__aiya_test_current_user_id'] = 0;
        }

        self::assertArrayNotHasKey('/wp/v2/posts', $locked);
        self::assertArrayHasKey('/aiya/core/v1/content', $locked);

        // The same session level clears the raised gate and keeps the whole API.
        $GLOBALS['__aiya_test_caps'] = true;
        self::assertArrayHasKey(
            '/wp/v2/posts',
            $this->module->lockWpV2($endpoints),
            'a contributor-level session clears the contributor gate'
        );
    }
}
