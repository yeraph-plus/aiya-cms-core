<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\MetaboxAdmin;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The metabox save path around group storage: an action-checkbox-only box
 * (typography shape) must never plant an empty serialized-array row — the
 * group key is deleted on save instead — while a box with persistable
 * fields keeps storing its values.
 */
final class MetaboxAdminSaveTest extends TestCase
{
    private MetadataRegistry $registry;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_post_meta'] = [];
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
        \add_action('aiya_core_test_refresh', static function ($null, int $postId, string $boxId) use (&$fired): void {
            $fired[] = [$postId, $boxId];
        }, 10, 3);

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
}
