<?php

use FpHic\HicS2S\Http\Routes;

class WebhookConversionRouteMethodTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();

        rest_get_server()->reset_registrations();
        Routes::registerRoutes();
    }

    public function test_get_requests_are_rejected(): void {
        $request = new WP_REST_Request('GET', '/hic/v1/conversion');
        $response = rest_do_request($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_no_route', $response->get_error_code());
    }

    public function test_post_without_token_is_unauthorized(): void {
        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $response = rest_do_request($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('hic_invalid_token', $response->get_error_code());
        $this->assertSame(401, $response->get_error_data()['status'] ?? null);
    }
}
