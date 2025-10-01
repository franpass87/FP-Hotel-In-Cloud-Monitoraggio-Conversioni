<?php declare(strict_types=1);

namespace FpHic\HicS2S\Support;

final class WpHttp
{
    /**
     * Safely retrieve a header value from a WordPress-style HTTP response.
     *
     * @param mixed $response
     */
    public static function retrieveHeader($response, string $header): ?string
    {
        $value = self::retrieveHeaderWithWordPressFunction($response, $header);

        if ($value !== null) {
            return $value;
        }

        foreach (self::collectHeaderCandidates($response) as $headers) {
            $value = self::normaliseHeaderValueFromCollection($headers, $header);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param mixed $response
     */
    private static function retrieveHeaderWithWordPressFunction($response, string $header): ?string
    {
        if (!\function_exists('wp_remote_retrieve_header')) {
            return null;
        }

        $value = \wp_remote_retrieve_header($response, $header);

        return self::normaliseHeaderValue($value);
    }

    /**
     * @param mixed $headers
     */
    private static function normaliseHeaderValueFromCollection($headers, string $header): ?string
    {
        if ($headers === null) {
            return null;
        }

        $header = \trim($header);
        $normalisedHeader = self::normaliseHeaderName($header);
        $headerVariants = self::generateHeaderNameVariants($header);

        if (\is_string($headers)) {
            $lines = \preg_split('/\r\n|\n|\r/', $headers);

            if ($lines !== false) {
                foreach ($lines as $line) {
                    if (!\is_string($line) || $line === '') {
                        continue;
                    }

                    $separatorPosition = \strpos($line, ':');

                    if ($separatorPosition === false) {
                        continue;
                    }

                    $name = \substr($line, 0, $separatorPosition);

                    if (!\is_string($name) || self::normaliseHeaderName($name) !== $normalisedHeader) {
                        continue;
                    }

                    $value = \substr($line, $separatorPosition + 1);

                    if (!\is_string($value)) {
                        continue;
                    }

                    $normalisedValue = self::normaliseHeaderValue($value);

                    if ($normalisedValue !== null) {
                        return $normalisedValue;
                    }
                }
            }
        }

        if (\is_array($headers)) {
            foreach ($headerVariants as $candidate) {
                if (!\array_key_exists($candidate, $headers)) {
                    continue;
                }

                $value = self::normaliseHeaderValue($headers[$candidate]);

                if ($value !== null) {
                    return $value;
                }
            }

            foreach ($headers as $key => $value) {
                if (\is_string($key) && self::normaliseHeaderName($key) === $normalisedHeader) {
                    return self::normaliseHeaderValue($value);
                }
            }
        }

        if ($headers instanceof \ArrayAccess) {
            foreach ($headerVariants as $candidate) {
                if (!$headers->offsetExists($candidate)) {
                    continue;
                }

                $value = self::normaliseHeaderValue($headers[$candidate]);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        if ($headers instanceof \Traversable) {
            foreach ($headers as $key => $value) {
                if (!\is_string($key)) {
                    continue;
                }

                if (self::normaliseHeaderName($key) !== $normalisedHeader) {
                    continue;
                }

                $normalisedValue = self::normaliseHeaderValue($value);

                if ($normalisedValue !== null) {
                    return $normalisedValue;
                }
            }
        }

        if (\is_object($headers)) {
            foreach (\get_object_vars($headers) as $key => $value) {
                if (!\is_string($key)) {
                    continue;
                }

                if (self::normaliseHeaderName($key) !== $normalisedHeader) {
                    continue;
                }

                $normalisedValue = self::normaliseHeaderValue($value);

                if ($normalisedValue !== null) {
                    return $normalisedValue;
                }
            }

            foreach (['getHeaderLine', 'get_header_line'] as $method) {
                if (!\method_exists($headers, $method)) {
                    continue;
                }

                foreach ($headerVariants as $candidate) {
                    $value = self::normaliseHeaderValue($headers->{$method}($candidate));

                    if ($value !== null) {
                        return $value;
                    }
                }
            }

            foreach (['getHeader', 'get_header'] as $method) {
                if (!\method_exists($headers, $method)) {
                    continue;
                }

                foreach ($headerVariants as $candidate) {
                    $value = $headers->{$method}($candidate);

                    if (\is_array($value)) {
                        $value = $value[0] ?? null;
                    }

                    $value = self::normaliseHeaderValue($value);

                    if ($value !== null) {
                        return $value;
                    }
                }
            }

            if (\method_exists($headers, 'get_headers')) {
                $allHeaders = $headers->get_headers();

                if ($allHeaders !== $headers) {
                    $value = self::normaliseHeaderValueFromCollection($allHeaders, $header);

                    if ($value !== null) {
                        return $value;
                    }
                }
            }

            if (\method_exists($headers, 'getHeaders')) {
                $allHeaders = $headers->getHeaders();

                if ($allHeaders !== $headers) {
                    $value = self::normaliseHeaderValueFromCollection($allHeaders, $header);

                    if ($value !== null) {
                        return $value;
                    }
                }
            }

            if (\method_exists($headers, 'getValues')) {
                foreach ($headerVariants as $candidate) {
                    $values = $headers->getValues($candidate);

                    if (\is_array($values) && $values !== []) {
                        return self::normaliseHeaderValue($values[0]);
                    }
                }
            }

            if (\method_exists($headers, 'getAll')) {
                $all = $headers->getAll();

                return self::normaliseHeaderValueFromCollection($all, $header);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function generateHeaderNameVariants(string $header): array
    {
        $variants = [];
        $header = \trim($header);

        if ($header !== '') {
            $variants[] = $header;
        }

        $normalisers = [
            static fn (string $value): string => $value,
            static fn (string $value): string => \strtolower($value),
            static fn (string $value): string => \strtoupper($value),
        ];

        foreach ($normalisers as $normaliser) {
            $candidate = $normaliser($header);

            if ($candidate !== '' && !\in_array($candidate, $variants, true)) {
                $variants[] = $candidate;
            }
        }

        $parts = \preg_split('/[-_\s]+/', $header, -1, \PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return $variants;
        }

        $lowerParts = \array_map(static fn (string $part): string => \strtolower($part), $parts);

        $formatters = [
            static fn (string $segment): string => \ucfirst(\strtolower($segment)),
            static fn (string $segment): string => \strtolower($segment),
            static fn (string $segment): string => \strtoupper($segment),
        ];

        $glues = ['-', '_', '', ' '];

        foreach ($glues as $glue) {
            foreach ($formatters as $formatter) {
                $candidate = \implode(
                    $glue,
                    \array_map(static fn (string $segment): string => $formatter($segment), $lowerParts)
                );

                if ($candidate !== '' && !\in_array($candidate, $variants, true)) {
                    $variants[] = $candidate;
                }
            }
        }

        return $variants;
    }

    private static function normaliseHeaderName(string $name): string
    {
        $name = \trim($name);

        if ($name === '') {
            return '';
        }

        $name = \preg_replace('/[-_\s]+/', '-', $name);

        if (!\is_string($name)) {
            $name = '';
        }

        return \strtolower($name);
    }

    /**
     * @param mixed $value
     */
    private static function normaliseHeaderValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (\is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (\is_string($value)) {
            $value = \trim($value);

            return $value === '' ? null : $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_object($value) && \method_exists($value, '__toString')) {
            $stringValue = (string) $value;
            $stringValue = \trim($stringValue);

            return $stringValue === '' ? null : $stringValue;
        }

        return null;
    }

    /**
     * @param mixed $response
     * @return list<mixed>
     */
    private static function collectHeaderCandidates($response): array
    {
        $candidates = [];

        if (\is_array($response)) {
            if (\array_key_exists('headers', $response)) {
                $candidates[] = $response['headers'];
            }

            if (\array_key_exists('http_response', $response) && $response['http_response'] !== $response) {
                $candidates = \array_merge($candidates, self::collectHeaderCandidates($response['http_response']));
            }

            return $candidates;
        }

        if (\is_object($response)) {
            $candidates[] = $response;

            if (isset($response->headers)) {
                /** @phpstan-ignore-next-line property access is intentionally dynamic */
                $candidates[] = $response->headers;
            }

            if (isset($response->http_response) && $response->http_response !== $response) {
                /** @phpstan-ignore-next-line property access is intentionally dynamic */
                $candidates = \array_merge($candidates, self::collectHeaderCandidates($response->http_response));
            }

            if (\method_exists($response, 'get_headers')) {
                $candidates[] = $response->get_headers();
            }

            if (\method_exists($response, 'getHeaders')) {
                $candidates[] = $response->getHeaders();
            }
        }

        return $candidates;
    }
}
