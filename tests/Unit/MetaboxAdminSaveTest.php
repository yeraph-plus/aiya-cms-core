<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\MetaboxAdmin;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use PHPUnit\Framework\TestCase;
use WP_Post;

require_once __DIR__ . '/../wp-shims.php';

/**
 * The metabox save path around group storage: an action-checkbox-only box
 * (typography shape) must never plant an empty serialized-array row — the
 * group key is deleted on save instead — and normalized empties (blank
 * string, null, empty list, unticked switch) are kicked from every group,
 * so a partly emptied box stores only the non-empty fields and a fully
 * emptied one deletes the key; read-back falls back to the field default.
 */
final class MetaboxAdminSaveTest extends TestCase
{
    private MetadataRegistry $registry;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_term_meta'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $_POST = [];
        $this->registry = new MetadataRegistry();
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function admin(): MetaboxAdmin
    {
        return new MetaboxAdmin($this->registry);
    }

    private function post(int $id): WP_Post
    {
        return new WP_Post((object) ['ID' => $id, 'post_type' => 'post']);
    }

    public function testActionOnlyBoxDeletesInsteadOfWritingAnEmptyRow(): void
    {
        $this->registry->addPostBox([
            'id' => 'typography',
            'title' => 'Typography tools',
            'screens' => ['post'],
            'context' => 'side',
            'fields' => [[
                'id' => 'refresh_date',
                'type' => 'action_checkbox',
                'label' => 'Refresh',
                'action' => 'aiya_core_test_refresh',
            ]],
        ]);

        // The legacy bug: every save planted a serialized empty-array row.
        update_post_meta(5, 'aiya_core_typography', []);

        $_POST['aiya_core_box_nonce_typography'] = 'nonce';
        $_POST['aiya_core_actions']['refresh_date'] = '1';

        $fired = [];
        \add_action('aiya_core_test_refresh', static function (int $postId, string $boxId) use (&$fired): void {
            $fired[] = [$postId, $boxId];
        }, 10, 2);

        $this->admin()->savePostBoxes(5, $this->post(5));

        self::assertSame('', \get_post_meta(5, 'aiya_core_typography', true), 'no empty-array row is written or kept');
        self::assertSame([[5, 'typography']], $fired, 'the one-shot action still fires');
    }

    public function testPersistableBoxKeepsStoringItsValues(): void
    {
        $this->registry->addPostBox([
            'id' => 'notes',
            'title' => 'Notes',
            'screens' => ['post'],
            'context' => 'normal',
            'fields' => [[
                'id' => 'greeting',
                'type' => 'text',
                'label' => 'Greeting',
                'default' => '',
            ]],
        ]);

        $_POST['aiya_core_box_nonce_notes'] = 'nonce';
        $_POST['aiya_core_meta']['notes']['greeting'] = 'hello';

        $this->admin()->savePostBoxes(6, $this->post(6));

        self::assertSame(['greeting' => 'hello'], \get_post_meta(6, 'aiya_core_notes', true));
    }

    public function testBoxWithoutItsNonceIsSkipped(): void
    {
        $this->registry->addPostBox([
            'id' => 'notes',
            'title' => 'Notes',
            'screens' => ['post'],
            'context' => 'normal',
            'fields' => [[
                'id' => 'greeting',
                'type' => 'text',
                'label' => 'Greeting',
                'default' => '',
            ]],
        ]);

        $this->admin()->savePostBoxes(7, $this->post(7));

        self::assertSame('', \get_post_meta(7, 'aiya_core_notes', true), 'screens without our boxes are skipped');
    }

    // ------------------------------------------------------- the empty kick

    public function testAPartlyEmptyGroupStoresOnlyNonEmptyFields(): void
    {
        $this->registry->addPostBox([
            'id' => 'notes',
            'title' => 'Notes',
            'screens' => ['post'],
            'context' => 'normal',
            'fields' => [
                ['id' => 'greeting', 'type' => 'text', 'label' => 'Greeting', 'default' => ''],
                ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'default' => ''],
            ],
        ]);

        $_POST['aiya_core_box_nonce_notes'] = 'nonce';
        $_POST['aiya_core_meta']['notes']['greeting'] = 'hello';
        $_POST['aiya_core_meta']['notes']['note'] = '';

        $this->admin()->savePostBoxes(6, $this->post(6));

        self::assertSame(['greeting' => 'hello'], \get_post_meta(6, 'aiya_core_notes', true), 'the empty field is kicked; read-back falls back to its default');
    }

    public function testAnAllEmptyGroupDeletesTheMetaKey(): void
    {
        $this->registry->addPostBox([
            'id' => 'notes',
            'title' => 'Notes',
            'screens' => ['post'],
            'context' => 'normal',
            'fields' => [
                ['id' => 'greeting', 'type' => 'text', 'label' => 'Greeting', 'default' => ''],
                ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'default' => ''],
            ],
        ]);

        update_post_meta(6, 'aiya_core_notes', ['greeting' => 'stale', 'note' => 'rows']);

        $_POST['aiya_core_box_nonce_notes'] = 'nonce';

        $this->admin()->savePostBoxes(6, $this->post(6));

        self::assertSame('', \get_post_meta(6, 'aiya_core_notes', true), 'a fully emptied group deletes the key instead of storing an empty row');
    }

    public function testAnUntickedSwitchIsKickedFromTheGroup(): void
    {
        $this->registry->addPostBox([
            'id' => 'misc',
            'title' => 'Misc',
            'screens' => ['post'],
            'context' => 'normal',
            'fields' => [
                ['id' => 'flag', 'type' => 'switch', 'label' => 'Flag', 'default' => false],
                ['id' => 'greeting', 'type' => 'text', 'label' => 'Greeting', 'default' => ''],
            ],
        ]);

        update_post_meta(8, 'aiya_core_misc', ['flag' => false, 'greeting' => 'old']);

        $_POST['aiya_core_box_nonce_misc'] = 'nonce';
        $_POST['aiya_core_meta']['misc']['greeting'] = 'hello';

        $this->admin()->savePostBoxes(8, $this->post(8));

        self::assertSame(['greeting' => 'hello'], \get_post_meta(8, 'aiya_core_misc', true), 'false is kicked like any other empty, and a previously stored one is cleaned up');
    }

    public function testTermBoxEmptiesDeleteTheirKeys(): void
    {
        $this->registry->addTermBox([
            'id' => 'extras',
            'title' => 'Extras',
            'taxonomies' => ['category'],
            'fields' => [
                ['id' => 'icon', 'type' => 'text', 'label' => 'Icon', 'default' => ''],
                ['id' => 'label', 'type' => 'text', 'label' => 'Label', 'default' => ''],
            ],
        ]);
        $this->admin()->attachTermHooks();

        update_term_meta(3, 'icon', 'book');
        update_term_meta(3, 'label', 'kept');

        $_POST['aiya_core_term_nonce_extras'] = 'nonce';
        $_POST['aiya_core_term']['icon'] = '';
        $_POST['aiya_core_term']['label'] = 'kept';

        apply_filters('edited_category', 3);

        self::assertSame('', \get_term_meta(3, 'icon', true), 'the cleared field deletes its key');
        self::assertSame('kept', \get_term_meta(3, 'label', true), 'the non-empty field is stored as usual');
    }
}
