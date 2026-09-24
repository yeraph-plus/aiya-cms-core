<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Identity\FavoriteService;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Error;

/**
 * Favorites span every public type (post/page/resource): the write gate
 * rejects anything unpublished or outside the registry, and the table
 * itself stays type-agnostic (the list reads do the published filtering).
 */
final class FavoriteServiceTest extends TestCase
{
    private FavoriteService $service;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        global $wpdb;
        $wpdb = new \wpdb();
        $this->service = new FavoriteService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    private function seed(int $id, string $type, string $status): void
    {
        $GLOBALS['__aiya_test_posts'][$id] = new WP_Post((object) [
            'ID' => $id,
            'post_type' => $type,
            'post_status' => $status,
            'post_title' => 'Row ' . $id,
        ]);
    }

    public function testAcceptsAPublishedRowOfEveryPublicType(): void
    {
        $this->seed(1, 'post', 'publish');
        $this->seed(2, 'page', 'publish');
        $this->seed(3, 'resource', 'publish');

        foreach ([1 => 'post', 2 => 'page', 3 => 'resource'] as $id => $type) {
            $result = $this->service->add(7, $id);
            self::assertNotInstanceOf(WP_Error::class, $result, "type $type should be favoritable");
        }

        global $wpdb;
        $rows = $wpdb->aiya_test_rows['wp_aiya_user_favorites'] ?? [];
        self::assertSame([1, 2, 3], array_map(static fn (array $row): int => (int) $row['post_id'], $rows));
    }

    public function testRefusesUnpublishedRows(): void
    {
        $this->seed(10, 'post', 'draft');
        $this->seed(11, 'resource', 'draft');

        foreach ([10, 11] as $id) {
            $result = $this->service->add(7, $id);
            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_invalid_param', $result->get_error_code());
            self::assertSame(400, (int) (($result->get_error_data('aiya_invalid_param') ?? [])['status'] ?? 0));
        }

        global $wpdb;
        self::assertSame([], $wpdb->aiya_test_rows['wp_aiya_user_favorites'] ?? []);
    }

    public function testRefusesTypesOutsideThePublicRegistry(): void
    {
        $this->seed(20, 'attachment', 'publish');
        $this->seed(21, 'aiya_private_thing', 'publish');

        foreach ([20, 21] as $id) {
            self::assertInstanceOf(WP_Error::class, $this->service->add(7, $id));
        }

        global $wpdb;
        self::assertSame([], $wpdb->aiya_test_rows['wp_aiya_user_favorites'] ?? []);
    }

    public function testRefusesUnknownAndDegenerateIds(): void
    {
        self::assertInstanceOf(WP_Error::class, $this->service->add(7, 999));
        self::assertInstanceOf(WP_Error::class, $this->service->add(7, 0));
        self::assertInstanceOf(WP_Error::class, $this->service->add(7, -5));
    }
}
