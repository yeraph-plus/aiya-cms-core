<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\FileServeMetabox;
use Aiya\Core\Api\Presenter\FilePresenter;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\FileServe\Adapter;
use Aiya\Core\Domain\FileServe\AdapterRegistry;
use Aiya\Core\Domain\FileServe\Adapters\PlatformAdapter;
use Aiya\Core\Domain\FileServe\Config;
use Aiya\Core\Domain\FileServe\Entry;
use Aiya\Core\Domain\FileServe\FileService;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The editor's way in: the metabox renders the stored configuration as one
 * input plus the bootstrap the script builds the panels from, and the classic
 * save path stores what came back — or stores nothing and says so when a group
 * could not be read.
 */
final class FileServeMetaboxTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_object_cache'] = [];
        $GLOBALS['__aiya_test_transients'] = [];
        $GLOBALS['__aiya_test_caps'] = true;
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['__aiya_test_caps']);
    }

    private function adapters(): AdapterRegistry
    {
        $adapters = new AdapterRegistry();
        $adapters->register(new PlatformAdapter());
        $adapters->register($this->stubAdapter());

        return $adapters;
    }

    private function metabox(): FileServeMetabox
    {
        $adapters = $this->adapters();
        $files = new FileService($adapters, new PostVisibility(static fn (int $userId): bool => false));

        return new FileServeMetabox($adapters, $files, new FilePresenter());
    }

    private function stubAdapter(): Adapter
    {
        return new class implements Adapter {
            public function id(): string
            {
                return 'stub';
            }

            public function label(): string
            {
                return 'Stub adapter';
            }

            /** @return list<array<string, mixed>> */
            public function fields(): array
            {
                return [
                    ['id' => 'path', 'type' => 'text', 'label' => 'Path', 'description' => 'Where.', 'default' => ''],
                ];
            }

            /** @param array<string, mixed> $config */
            public function configured(array $config): bool
            {
                return true;
            }

            /**
             * @param array<string, mixed> $config
             * @return list<Entry>
             */
            public function entries(array $config): array
            {
                return [new Entry(name: 'report.pdf', kind: Entry::FILE, size: 9, path: '/docs/report.pdf', url: 'https://files.test/d/report.pdf')];
            }

            public function siteConfig(): array
            {
                return [];
            }
        };
    }

    private function post(int $id = 1): WP_Post
    {
        $post = new WP_Post((object) [
            'ID' => $id,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'File post',
        ]);
        $GLOBALS['__aiya_test_posts'][$id] = $post;

        return $post;
    }

    public function testTheBoxRendersOneInputAndTheBootstrapTheScriptNeeds(): void
    {
        $this->post();
        update_post_meta(1, Config::META_KEY, Config::encode([
            '1' => ['adapter' => 'stub', 'title' => '文档', 'path' => '/docs', 'price' => 5],
        ]));

        ob_start();
        $this->metabox()->render($GLOBALS['__aiya_test_posts'][1]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="aiya_core_fileserve_config"', $html);
        self::assertStringContainsString('value="{&quot;1&quot;', $html, 'the stored JSON travels in the hidden field');
        self::assertStringContainsString('name="aiya_core_fileserve_nonce"', $html);
        self::assertStringContainsString('id="aiya-fileserve-bootstrap"', $html);
        self::assertStringContainsString('"adapter":"stub"', $html);
        self::assertStringContainsString('"label":"Stub adapter"', $html, 'the picker is built from the registry');
        self::assertStringContainsString('"Credits per file"', $html, 'every group gets the common fields');
        self::assertStringContainsString('"nextId":"2"', $html);
        self::assertStringContainsString('"save":"aiya_core_fileserve_save"', $html);
    }

    public function testTheClassicSaveStoresWhatThePanelsHeld(): void
    {
        $this->post();
        $metabox = $this->metabox();

        $_POST['aiya_core_fileserve_nonce'] = 'aiya-test-nonce';
        $_POST[FileServeMetabox::FIELD] = (string) json_encode([
            '3' => ['adapter' => 'platform', 'title' => '夸克', 'url' => 'https://pan.quark.cn/s/abc', 'code' => 'x7k2', 'price' => 0],
        ]);

        $metabox->saveFromPost(1, $GLOBALS['__aiya_test_posts'][1]);

        $stored = (string) get_post_meta(1, Config::META_KEY, true);
        self::assertStringContainsString('"3"', $stored);
        self::assertStringContainsString('pan.quark.cn', $stored);
    }

    public function testBackslashesAndQuotesSurviveTheUnslashThenDecodeRoundtrip(): void
    {
        $this->post();
        // What the editor typed travels as JSON the script wrote; the value
        // below is a\b"c as three escapes the JSON text must carry intact.
        $json = (string) json_encode([
            '1' => ['adapter' => 'platform', 'title' => 'a\\b"c', 'url' => 'https://pan.quark.cn/s/abc', 'code' => '', 'price' => 0],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $_POST['nonce'] = 'aiya-test-nonce';
        $_POST['post_id'] = '1';
        // wp_magic_quotes() slashes whatever the browser posts; submitted()
        // must unslash that exact text back before json_decode reads it.
        $_POST[FileServeMetabox::FIELD] = wp_slash($json);

        try {
            $this->metabox()->handleSave();
            self::fail('the handler answers with a JSON envelope');
        } catch (\Aiya_Test_Json_Response $response) {
            self::assertTrue($response->success);
        }

        $config = Config::read(1, $this->adapters());
        self::assertSame('a\\b"c', $config['1']['title'] ?? null, 'a backslash is a backslash — never the control character a naive decode would make of it');
        self::assertSame('https://pan.quark.cn/s/abc', $config['1']['url'] ?? null);
    }

    public function testTheAjaxSaveStoresWhatCameBackAndAnswersItAsAnObject(): void
    {
        $this->post();
        $_POST['nonce'] = 'aiya-test-nonce';
        $_POST['post_id'] = '1';
        $_POST[FileServeMetabox::FIELD] = (string) json_encode([
            '3' => ['adapter' => 'platform', 'title' => '夸克', 'url' => 'https://pan.quark.cn/s/abc', 'code' => 'x7k2', 'price' => 5],
        ]);

        try {
            $this->metabox()->handleSave();
            self::fail('the handler answers with a JSON envelope');
        } catch (\Aiya_Test_Json_Response $response) {
            self::assertTrue($response->success);
            self::assertSame('4', $response->data['nextId'] ?? null, 'the next id counts one past the highest stored group');
            self::assertStringContainsString('"3"', (string) json_encode($response->data['config']), 'the answer carries the canonical configuration');
        }

        self::assertStringContainsString('pan.quark.cn', (string) get_post_meta(1, Config::META_KEY, true), 'the AJAX path stores like the classic save');
    }

    public function testAnEmptyAjaxSaveAnswersAnObjectNotAnArray(): void
    {
        $this->post();
        $_POST['nonce'] = 'aiya-test-nonce';
        $_POST['post_id'] = '1';
        $_POST[FileServeMetabox::FIELD] = '{}';

        try {
            $this->metabox()->handleSave();
            self::fail('the handler answers with a JSON envelope');
        } catch (\Aiya_Test_Json_Response $response) {
            self::assertTrue($response->success);
            // A PHP [] would travel as a JSON array, read as truthy in the
            // script, and silently drop every group added afterwards.
            self::assertSame('{}', json_encode($response->data['config']));
            self::assertSame('1', $response->data['nextId'] ?? null);
        }

        self::assertSame('', (string) get_post_meta(1, Config::META_KEY, true), 'an emptied configuration deletes the stored meta');
    }

    public function testTheAjaxSaveWithoutANonceNeverReachesTheStore(): void
    {
        $this->post();
        update_post_meta(1, Config::META_KEY, Config::encode([
            '1' => ['adapter' => 'platform', 'title' => '夸克', 'url' => 'https://pan.quark.cn/s/abc', 'code' => '', 'price' => 0],
        ]));

        $_POST['post_id'] = '1';
        $_POST[FileServeMetabox::FIELD] = '{"1":{"adapter":"platform","url":"https://a.test","price":0}}';
        // no nonce in the request

        try {
            $this->metabox()->handleSave();
            self::fail('a failed nonce check must stop the handler');
        } catch (\Aiya_Test_Abort) {
        }

        self::assertStringContainsString('pan.quark.cn', (string) get_post_meta(1, Config::META_KEY, true), 'nothing was written');
    }

    public function testTheAjaxSaveRefusesAPostThisUserCannotEdit(): void
    {
        $this->post();
        $GLOBALS['__aiya_test_caps'] = false;

        $_POST['nonce'] = 'aiya-test-nonce';
        $_POST['post_id'] = '1';
        $_POST[FileServeMetabox::FIELD] = '{"1":{"adapter":"platform","url":"https://a.test","price":0}}';

        try {
            $this->metabox()->handleSave();
            self::fail('the handler refuses with a 403 envelope');
        } catch (\Aiya_Test_Json_Response $response) {
            self::assertFalse($response->success);
            self::assertSame(403, $response->status);
        }

        self::assertSame('', (string) get_post_meta(1, Config::META_KEY, true), 'nothing was written');
    }

    public function testTheAjaxPreviewReadsThroughTheSharedProjection(): void
    {
        $this->post();
        $_POST['nonce'] = 'aiya-test-nonce';
        $_POST['post_id'] = '1';
        $_POST[FileServeMetabox::FIELD] = '{"1":{"adapter":"stub","path":"/docs","price":5}}';

        try {
            $this->metabox()->handlePreview();
            self::fail('the handler answers with a JSON envelope');
        } catch (\Aiya_Test_Json_Response $response) {
            self::assertTrue($response->success);
            $lists = $response->data['lists'] ?? [];
            self::assertCount(1, $lists);
            self::assertSame(5, $lists[0]['price']);
            self::assertNull($lists[0]['error'], 'a healthy group previews without an error note');
            self::assertSame('report.pdf', $lists[0]['items'][0]['name'] ?? null, 'the preview reads through the same projection the front end gets');
        }
    }

    public function testTheAjaxPreviewRefusesAPostThisUserCannotEdit(): void
    {
        $this->post();
        $GLOBALS['__aiya_test_caps'] = false;

        $_POST['nonce'] = 'aiya-test-nonce';
        $_POST['post_id'] = '1';
        $_POST[FileServeMetabox::FIELD] = '{"1":{"adapter":"stub","path":"/docs","price":0}}';

        try {
            $this->metabox()->handlePreview();
            self::fail('the handler refuses with a 403 envelope');
        } catch (\Aiya_Test_Json_Response $response) {
            self::assertFalse($response->success);
            self::assertSame(403, $response->status);
        }
    }

    public function testAnUnreadableConfigurationStoresNothingAndIsReported(): void
    {
        $this->post();
        update_post_meta(1, Config::META_KEY, Config::encode([
            '1' => ['adapter' => 'platform', 'title' => '夸克', 'url' => 'https://pan.quark.cn/s/abc', 'code' => '', 'price' => 0],
        ]));

        $metabox = $this->metabox();
        $_POST['aiya_core_fileserve_nonce'] = 'aiya-test-nonce';
        $_POST[FileServeMetabox::FIELD] = '{"1":{"adapter":"platform","url":"https://a.test","price":"abc"}}';

        $metabox->saveFromPost(1, $GLOBALS['__aiya_test_posts'][1]);

        self::assertStringContainsString('pan.quark.cn', (string) get_post_meta(1, Config::META_KEY, true), 'the stored value survives');
        $errors = get_transient('aiya_core_fileserve_save_errors_0');
        self::assertIsArray($errors);
        self::assertCount(1, $errors);
    }

    public function testWithoutTheNonceNothingIsWritten(): void
    {
        $this->post();
        $_POST[FileServeMetabox::FIELD] = '{"1":{"adapter":"platform","url":"https://a.test","price":0}}';

        $this->metabox()->saveFromPost(1, $GLOBALS['__aiya_test_posts'][1]);

        self::assertSame('', (string) get_post_meta(1, Config::META_KEY, true));
    }

    public function testTheBoxIsDeclaredOnEverySupportedContentType(): void
    {
        $GLOBALS['__aiya_test_meta_boxes'] = [];
        $this->metabox()->addMetaBox();
        self::assertSame(['post', 'page', 'resource'], array_column($GLOBALS['__aiya_test_meta_boxes'], 'screen'));
        self::assertSame('aiya-core-fileserve', $GLOBALS['__aiya_test_meta_boxes'][0]['id']);

        add_filter('aiya_core_fileserve_post_types', static function (array $types): array {
            $types[] = 'attachment';

            return $types;
        });
        $GLOBALS['__aiya_test_meta_boxes'] = [];
        $this->metabox()->addMetaBox();
        self::assertContains('attachment', array_column($GLOBALS['__aiya_test_meta_boxes'], 'screen'));
    }
}
