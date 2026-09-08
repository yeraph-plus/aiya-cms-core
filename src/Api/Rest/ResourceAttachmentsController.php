<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Attachment;
use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Domain\ExternalFiles\AttachmentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public attachment list of one resource (`GET /resources/{id}/attachments`).
 * Listing metadata is served to everyone; download links appear only for
 * viewers the resource's sponsor gate allows. Upstream failures map to
 * stable aiya_oplist_* error codes.
 */
final class ResourceAttachmentsController
{
    public function __construct(private AttachmentService $attachments)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/resources/(?P<id>\d+)/attachments', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->list($request),
            'permission_callback' => '__return_true',
            'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);
    }

    private function list(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $result = $this->attachments->forResource((int) $request->get_param('id'), (int) get_current_user_id());
        if (is_wp_error($result)) {
            return $result;
        }

        $items = [];
        foreach ($result['items'] as $item) {
            $items[] = (new Attachment(
                $item['name'],
                $item['size'],
                $item['type'],
                $item['modified'],
                $item['url'],
                $item['ready'],
            ))->toArray();
        }

        return new WP_REST_Response([
            'gated' => $result['gated'],
            'canSeeLinks' => $result['canSeeLinks'],
            'items' => $items,
        ]);
    }
}
