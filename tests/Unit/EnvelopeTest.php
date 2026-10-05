<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Contract\Pagination;
    use Aiya\Core\Api\Rest\Envelope;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';
    use WP_Error;

    /**
     * The {data, meta} envelope is the front end's validation surface, so
     * every failure shape (bare WP_Error, core's pre-dispatch error
     * response, unnamed errors) must leave here canonical.     */
    final class EnvelopeTest extends TestCase
    {
        private FakeRestServer $server;

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_filters'] = [];
            $this->server = new FakeRestServer();
        }

        private function contractRequest(): FakeRestRequest
        {
            return new FakeRestRequest('/aiya/core/v1/posts');
        }

        private function foreignRequest(): FakeRestRequest
        {
            return new FakeRestRequest('/wp/v2/posts');
        }

        private function response(mixed $data): FakeRestResponse
        {
            return new FakeRestResponse($data);
        }

        public function testRegisterWiresApplyOntoRestPostDispatch(): void
        {
            Envelope::register();

            self::assertTrue(has_action('rest_post_dispatch', [Envelope::class, 'apply']));
            $buckets = $GLOBALS['__aiya_test_filters']['rest_post_dispatch'];
            self::assertSame([10], array_keys($buckets));
            self::assertSame(3, $buckets[10][0]['args']);
            self::assertSame([Envelope::class, 'apply'], $buckets[10][0]['callback']);
        }

        public function testApplyPassesForeignRoutesThroughUnchanged(): void
        {
            $out = Envelope::apply(42, $this->server, $this->foreignRequest());

            self::assertSame(42, $out);
        }

        public function testApplyPassesUnshapedContractScalarsThrough(): void
        {
            $out = Envelope::apply('raw', $this->server, $this->contractRequest());

            self::assertSame('raw', $out);
        }

        public function testApplyEnvelopesBareWpErrors(): void
        {
            $error = new WP_Error('aiya_not_logged_in', 'Authentication required.', ['status' => 401]);

            $out = Envelope::apply($error, $this->server, $this->contractRequest());

            self::assertInstanceOf(FakeRestResponse::class, $out);
            self::assertSame(401, $out->get_status());
            $data = $out->get_data();
            self::assertSame(
                ['code' => 'aiya_not_logged_in', 'message' => 'Authentication required.', 'status' => 401],
                $data['error']
            );
            self::assertSame('1', $data['meta']['apiVersion']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $data['meta']['requestId']);
        }

        public function testApplyDefaultsTo500WhenErrorCarriesNoStatus(): void
        {
            $out = Envelope::apply(new WP_Error('aiya_boom', 'kaboom'), $this->server, $this->contractRequest());

            self::assertSame(500, $out->get_status());
            self::assertSame(
                ['code' => 'aiya_boom', 'message' => 'kaboom', 'status' => 500],
                $out->get_data()['error']
            );
        }

        public function testApplyDefaultsTo500WhenErrorStatusIsOutOfRange(): void
        {
            $below = Envelope::apply(new WP_Error('x', 'y', ['status' => 302]), $this->server, $this->contractRequest());
            $above = Envelope::apply(new WP_Error('x', 'y', ['status' => 601]), $this->server, $this->contractRequest());

            self::assertSame(500, $below->get_status());
            self::assertSame(500, $above->get_status());
        }

        public function testApplyDefaultsTo500WhenErrorStatusIsNotAnInt(): void
        {
            $out = Envelope::apply(new WP_Error('x', 'y', ['status' => '404']), $this->server, $this->contractRequest());

            self::assertSame(500, $out->get_status());
        }

        /** An empty error code stores nothing in WP_Error, so the fallback name answers with the generic 500 face. */
        public function testApplyNamesAnonymousErrorsAsServerError(): void
        {
            $out = Envelope::apply(new WP_Error('', 'boom', ['status' => 403]), $this->server, $this->contractRequest());

            self::assertSame(
                ['code' => 'aiya_server_error', 'message' => '', 'status' => 500],
                $out->get_data()['error']
            );
            self::assertSame(500, $out->get_status());
        }

        public function testApplyReEnvelopesCoreErrorResponses(): void
        {
            $in = $this->response(['code' => 'rest_no_route', 'message' => 'No route was found.', 'data' => ['status' => 404]]);

            $out = Envelope::apply($in, $this->server, $this->contractRequest());

            self::assertInstanceOf(FakeRestResponse::class, $out);
            self::assertNotSame($in, $out);
            self::assertSame(404, $out->get_status());
            self::assertSame(
                ['code' => 'rest_no_route', 'message' => 'No route was found.', 'status' => 404],
                $out->get_data()['error']
            );
        }

        /** Core-shaped data without a status key is a legitimate controller payload, not an error face. */
        public function testApplyTreatsCoreShapedPayloadWithoutStatusAsData(): void
        {
            $payload = ['code' => 'custom', 'message' => 'info', 'data' => ['params' => ['x']]];
            $in = $this->response($payload);

            $out = Envelope::apply($in, $this->server, $this->contractRequest());

            self::assertSame($in, $out);
            self::assertSame($payload, $out->get_data()['data']);
            self::assertSame('1', $out->get_data()['meta']['apiVersion']);
        }

        /** Controllers that assembled the full meta themselves (lists with pagination) are left alone. */
        public function testApplyLeavesControllerMetaAssembliesUntouched(): void
        {
            $payload = ['data' => ['id' => 1], 'meta' => ['apiVersion' => '1', 'requestId' => 'abcd1234']];
            $in = $this->response($payload);

            $out = Envelope::apply($in, $this->server, $this->contractRequest());

            self::assertSame($in, $out);
            self::assertSame($payload, $out->get_data());
        }

        public function testApplyLeavesNonArrayResponseDataUntouched(): void
        {
            $in = $this->response('plain-string');

            $out = Envelope::apply($in, $this->server, $this->contractRequest());

            self::assertSame($in, $out);
            self::assertSame('plain-string', $out->get_data());
        }

        public function testApplyWrapsPlainControllerArraysInTheEnvelope(): void
        {
            $in = $this->response(['id' => 7]);

            $out = Envelope::apply($in, $this->server, $this->contractRequest());

            self::assertSame($in, $out);
            self::assertSame(['id' => 7], $out->get_data()['data']);
            self::assertSame('1', $out->get_data()['meta']['apiVersion']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $out->get_data()['meta']['requestId']);
        }

        public function testMetaCarriesApiVersionAndAFreshRequestIdPerCall(): void
        {
            $meta = Envelope::meta();

            self::assertSame('1', $meta['apiVersion']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $meta['requestId']);
            self::assertSame(['apiVersion', 'requestId'], array_keys($meta));
            self::assertNotSame(Envelope::meta()['requestId'], $meta['requestId']);
        }

        public function testPayloadWithoutPaginationCarriesBareMeta(): void
        {
            $out = Envelope::payload(['a', 'b']);

            self::assertSame(200, $out->get_status());
            $data = $out->get_data();
            self::assertSame(['a', 'b'], $data['data']);
            self::assertSame(['apiVersion', 'requestId'], array_keys($data['meta']));
            self::assertSame('1', $data['meta']['apiVersion']);
        }

        public function testPayloadAppendsPaginationVerbatim(): void
        {
            $out = Envelope::payload([], Pagination::fromCounts(2, 10, 35));

            self::assertSame(
                ['page' => 2, 'perPage' => 10, 'totalItems' => 35, 'totalPages' => 4, 'hasNext' => true, 'hasPrevious' => true],
                $out->get_data()['meta']['pagination']
            );
        }

        public function testPayloadClampsDegeneratePagination(): void
        {
            $out = Envelope::payload([], Pagination::fromCounts(-3, 0, 0));

            self::assertSame(
                ['page' => 1, 'perPage' => 1, 'totalItems' => 0, 'totalPages' => 0, 'hasNext' => false, 'hasPrevious' => false],
                $out->get_data()['meta']['pagination']
            );
        }

        public function testPayloadFlagsSinglePageWithoutNeighbors(): void
        {
            $out = Envelope::payload([], Pagination::fromCounts(1, 10, 5));

            self::assertSame(
                ['page' => 1, 'perPage' => 10, 'totalItems' => 5, 'totalPages' => 1, 'hasNext' => false, 'hasPrevious' => false],
                $out->get_data()['meta']['pagination']
            );
        }
    }
}
