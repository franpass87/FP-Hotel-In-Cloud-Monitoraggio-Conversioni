<?php

declare(strict_types=1);

use FpHic\HicS2S\Support\WpHttp;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Support/WpHttp.php';

final class WpHttpTest extends TestCase
{
    public function testRetrievesHeaderFromArrayResponse(): void
    {
        $response = [
            'headers' => [
                'Retry-After' => ' 5 ',
            ],
        ];

        self::assertSame('5', WpHttp::retrieveHeader($response, 'retry-after'));
    }

    public function testRetrievesHeaderFromArrayWithUnderscoreKey(): void
    {
        $response = [
            'headers' => [
                'retry_after' => ' 19 ',
            ],
        ];

        self::assertSame('19', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromArrayWithCamelCaseKey(): void
    {
        $response = [
            'headers' => [
                'RetryAfter' => ' 23 ',
            ],
        ];

        self::assertSame('23', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromResponseWithHeadersProperty(): void
    {
        $headers = new class(['Retry-After' => ['7']]) implements \ArrayAccess
        {
            /** @var array<string,list<string>> */
            private array $values;

            /**
             * @param array<string,mixed> $headers
             */
            public function __construct(array $headers)
            {
                $this->values = [];

                foreach ($headers as $name => $value) {
                    $key = strtolower((string) $name);
                    $this->values[$key] = array_map('strval', (array) $value);
                }
            }

            public function offsetExists(mixed $offset): bool
            {
                $key = strtolower((string) $offset);

                return array_key_exists($key, $this->values);
            }

            public function offsetGet(mixed $offset): mixed
            {
                $key = strtolower((string) $offset);

                return $this->values[$key] ?? [];
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $key = strtolower((string) $offset);
                $this->values[$key] = array_map('strval', (array) $value);
            }

            public function offsetUnset(mixed $offset): void
            {
                $key = strtolower((string) $offset);
                unset($this->values[$key]);
            }

            /**
             * @return list<string>
             */
            public function getValues(string $name): array
            {
                $key = strtolower($name);

                return $this->values[$key] ?? [];
            }

            /**
             * @return array<string,list<string>>
             */
            public function getAll(): array
            {
                return $this->values;
            }
        };

        $response = new class($headers)
        {
            /**
             * @var \ArrayAccess&object
             */
            public $headers;

            public function __construct($headers)
            {
                $this->headers = $headers;
            }
        };

        self::assertSame('7', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromRawHeaderString(): void
    {
        $response = [
            'headers' => "Date: Wed, 01 Jan 2025 00:00:00 GMT\r\nRetry-After: 47\r\nServer: nginx",
        ];

        self::assertSame('47', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromCaseSensitiveArrayAccess(): void
    {
        $headers = new \ArrayObject(['Retry-After' => '17']);

        $response = new class($headers)
        {
            /** @var \ArrayAccess */
            public $headers;

            public function __construct($headers)
            {
                $this->headers = $headers;
            }
        };

        self::assertSame('17', WpHttp::retrieveHeader($response, 'retry-after'));
    }

    public function testRetrievesHeaderFromCaseSensitiveArrayAccessWithUnderscore(): void
    {
        $headers = new \ArrayObject(['Retry_After' => '29']);

        $response = new class($headers)
        {
            /** @var \ArrayAccess */
            public $headers;

            public function __construct($headers)
            {
                $this->headers = $headers;
            }
        };

        self::assertSame('29', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromCaseSensitiveArrayAccessWithSpace(): void
    {
        $headers = new \ArrayObject(['retry after' => '41']);

        $response = new class($headers)
        {
            /** @var \ArrayAccess */
            public $headers;

            public function __construct($headers)
            {
                $this->headers = $headers;
            }
        };

        self::assertSame('41', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromTraversableHeaders(): void
    {
        $headers = new class implements \IteratorAggregate
        {
            /** @var array<string,mixed> */
            private array $values;

            public function __construct()
            {
                $this->values = [
                    'Retry-After' => ['59'],
                ];
            }

            public function getIterator(): \Traversable
            {
                yield from $this->values;
            }
        };

        $response = new class($headers)
        {
            /** @var object */
            public $headers;

            public function __construct($headers)
            {
                $this->headers = $headers;
            }
        };

        self::assertSame('59', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderWhenArrayAccessValueCannotBeNormalised(): void
    {
        $headers = new class implements \ArrayAccess
        {
            public function offsetExists(mixed $offset): bool
            {
                return strtolower((string) $offset) === 'retry-after';
            }

            public function offsetGet(mixed $offset): mixed
            {
                return new class
                {
                };
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }

            /**
             * @return array<string,string>
             */
            public function getAll(): array
            {
                return ['Retry-After' => '13'];
            }
        };

        $response = new class($headers)
        {
            /** @var \ArrayAccess */
            public $headers;

            public function __construct($headers)
            {
                $this->headers = $headers;
            }
        };

        self::assertSame('13', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromResponseWithGetter(): void
    {
        $response = new class
        {
            /**
             * @return array<string,string>
             */
            public function get_headers(): array
            {
                return [
                    'retry-after' => '11',
                ];
            }
        };

        self::assertSame('11', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromObjectProperties(): void
    {
        $response = new class
        {
            /** @var string */
            public $retry_after = '31';

            /** @var string */
            public $RetryAfter = 'should not be used';
        };

        self::assertSame('31', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testReturnsNullWhenHeaderMissing(): void
    {
        $response = new class
        {
            public function get_headers(): array
            {
                return [];
            }
        };

        self::assertNull(WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromHttpResponseWrapper(): void
    {
        $response = [
            'headers' => [],
            'http_response' => new class
            {
                /**
                 * @return array<string,string>
                 */
                public function get_headers(): array
                {
                    return [
                        'Retry-After' => '17',
                    ];
                }
            },
        ];

        self::assertSame('17', WpHttp::retrieveHeader($response, 'Retry-After'));
    }

    public function testRetrievesHeaderFromPsr7StyleResponse(): void
    {
        $response = new class
        {
            public function getHeaderLine(string $name): string
            {
                if (strtolower($name) === 'retry-after') {
                    return '23';
                }

                return '';
            }
        };

        self::assertSame('23', WpHttp::retrieveHeader($response, 'Retry-After'));
    }
}
