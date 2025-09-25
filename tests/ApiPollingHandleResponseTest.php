<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiPollingHandleResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../includes/constants.php';
        require_once __DIR__ . '/../includes/functions.php';
        require_once __DIR__ . '/../includes/helpers-logging.php';
        require_once __DIR__ . '/../includes/api/polling.php';
    }

    public function testHttpError400FromSecureWrapperMapsToTimestampError(): void
    {
        $body = 'Il timestamp cannot be older than seven days';
        $error = new WP_Error('http_error', 'Errore HTTP 400', [
            'status' => 400,
            'body' => $body,
        ]);

        $result = \FpHic\hic_handle_api_response($error, 'HIC updates fetch');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('hic_timestamp_too_old', $result->get_error_code());
    }

    public function testHttpError400FromSecureWrapperReturnsGenericErrorWhenNotTimestamp(): void
    {
        $body = 'Parametro mancante: date_type';
        $error = new WP_Error('http_error', 'Errore HTTP 400', [
            'status' => 400,
            'body' => $body,
        ]);

        $result = \FpHic\hic_handle_api_response($error, 'HIC reservations fetch');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('hic_http', $result->get_error_code());
    }
}
