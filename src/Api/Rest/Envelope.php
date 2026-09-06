<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Response envelope for the headless contract: every aiya/core/v1 response
 * leaves as `{data, meta:{apiVersion, requestId}}` (lists add
 * `meta.pagination` from B1 on) and every failure as
 * `{error:{code, message, status}, meta}` — including permission and
 * argument-validation errors raised before any controller runs. The front
 * end's client validates this shape, so the envelope is applied centrally
 * on rest_post_dispatch instead of per controller.
 */
final class Envelope
{
    public static function register(): void
    {
        add_filter('rest_post_dispatch', [self::class, 'apply'], 10, 3);
    }

    /**
     * @param WP_Error|WP_REST_Response|mixed $result
     * @return WP_Error|WP_REST_Response|mixed
     */
    public static function apply(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if (!str_starts_with($request->get_route(), '/' . Contract::API_NAMESPACE)) {
            return $result;
        }

        if ($result instanceof WP_Error) {
            return new WP_REST_Response(['error' => self::errorBody($result), 'meta' => self::meta()], self::errorStatus($result));
        }

        if ($result instanceof WP_REST_Response) {
            $data = $result->get_data();
            if (!is_array($data)) {
                return $result;
            }

            // Core converts WP_Error to this shape in respond() before the
            // filter fires; recognize and re-envelope it.
            if (isset($data['code'], $data['message'], $data['data']['status'])
                && is_array($data['data'])
                && !isset($data['meta'])) {
                $status = (int) $data['data']['status'];
                $error = new WP_Error((string) $data['code'], (string) $data['message'], ['status' => $status]);

                return new WP_REST_Response(['error' => self::errorBody($error), 'meta' => self::meta()], self::errorStatus($error));
            }

            // Controllers may build the full meta themselves (lists with pagination).
            if (isset($data['meta']) && is_array($data['meta'])) {
                return $result;
            }

            $result->set_data(['data' => $data, 'meta' => self::meta()]);

            return $result;
        }

        return $result;
    }

    /** @return array{apiVersion: string, requestId: string} */
    public static function meta(): array
    {
        return [
            'apiVersion' => Contract::VERSION,
            'requestId' => bin2hex(random_bytes(4)),
        ];
    }

    /** @return array{code: string, message: string, status: int} */
    private static function errorBody(WP_Error $error): array
    {
        $code = (string) $error->get_error_code();

        return [
            'code' => $code !== '' ? $code : 'aiya_server_error',
            'message' => $error->get_error_message(),
            'status' => self::errorStatus($error),
        ];
    }

    private static function errorStatus(WP_Error $error): int
    {
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status']) && is_int($data['status'])
            && $data['status'] >= 400 && $data['status'] <= 599) {
            return $data['status'];
        }

        return 500;
    }
}
