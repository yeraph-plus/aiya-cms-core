<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\SmiliesItem;
use Aiya\Core\Api\Contract\SmiliesPack;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public read for the directory-scanned smilies map (`GET /smilies`).
 * Kept off /site deliberately: a real deployment carries hundreds of
 * tokens (~50KB), which would ride along every shell payload for data
 * only the comment/community surfaces consume.
 */
final class SmiliesController
{
    public function __construct(private readonly SmiliesRegistry $smilies)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/smilies', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => new WP_REST_Response($this->packs()),
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * The full pack map in contract shape; empty (never an error) when no
     * pack directory is deployed.
     *
     * @return list<array{slug: string, items: list<array{code: string, url: string}>}>
     */
    private function packs(): array
    {
        $out = [];
        foreach ($this->smilies->packs() as $pack) {
            $items = [];
            foreach ($pack['items'] as $item) {
                $items[] = new SmiliesItem((string) $item['code'], (string) $item['url']);
            }
            $out[] = (new SmiliesPack((string) $pack['slug'], $items))->toArray();
        }

        return $out;
    }
}
