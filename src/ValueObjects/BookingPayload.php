<?php declare(strict_types=1);

namespace FpHic\HicS2S\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

use FpHic\HicS2S\Support\Hasher;

final class BookingPayload
{
    private string $bookingCode;

    private string $status;

    private ?string $checkin;

    private ?string $checkout;

    private string $currency;

    private float $amount;

    private ?string $guestEmail;

    private ?string $guestPhone;

    private ?int $rooms;

    private ?int $adults;

    private ?int $children;

    private string $bucket;

    /** @var array<string,mixed> */
    private array $raw;

    /** @var array<string,string> */
    private array $identifiers;

    private ?string $bookingIntentId;

    private ?string $sid;

    private ?string $clientIp;

    private ?string $clientUserAgent;

    private ?string $eventSourceUrl;

    private ?int $eventTimestampMicros;

    private ?string $eventId;

    /** @param array<string,mixed> $data */
    private function __construct(array $data)
    {
        $this->bookingCode = $data['booking_code'];
        $this->status = $data['status'];
        $this->checkin = $data['checkin'];
        $this->checkout = $data['checkout'];
        $this->currency = $data['currency'];
        $this->amount = $data['amount'];
        $this->guestEmail = $data['guest_email'];
        $this->guestPhone = $data['guest_phone'];
        $this->rooms = $data['rooms'];
        $this->adults = $data['adults'];
        $this->children = $data['children'];
        $this->bucket = $data['bucket'];
        $this->raw = $data['raw'];
        $this->identifiers = $data['identifiers'];
        $this->bookingIntentId = $data['booking_intent_id'];
        $this->sid = $data['sid'];
        $this->clientIp = $data['client_ip'];
        $this->clientUserAgent = $data['client_user_agent'];
        $this->eventSourceUrl = $data['event_source_url'];
        $this->eventTimestampMicros = $data['event_timestamp_micros'];
        $this->eventId = $data['event_id'];
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $bookingCode = self::cleanString($input['booking_code'] ?? '');

        if ($bookingCode === '') {
            throw new \InvalidArgumentException('Missing booking_code');
        }

        $status = self::cleanString($input['status'] ?? 'confirmed');

        if ($status === '') {
            $status = 'confirmed';
        }

        $checkin = self::cleanDate($input['checkin'] ?? null);
        $checkout = self::cleanDate($input['checkout'] ?? null);
        $currency = self::cleanCurrency($input['currency'] ?? null);
        $amount = self::cleanAmount($input['amount'] ?? 0);
        $guestEmail = self::cleanEmail($input['guest_email'] ?? null);
        $guestPhone = self::cleanPhone($input['guest_phone'] ?? null);

        $rooms = self::cleanInt($input['rooms'] ?? null);
        $adults = self::cleanInt($input['adults'] ?? null);
        $children = self::cleanInt($input['children'] ?? null);

        $identifiers = self::extractIdentifiers($input);

        $bucket = self::determineBucket($identifiers, $input);

        $clientIp = self::resolveClientIp($input);
        $clientUserAgent = self::resolveUserAgent($input);
        $eventSourceUrl = self::resolveEventSourceUrl($input);
        $eventTimestampMicros = self::resolveEventTimestampMicros($input);

        $eventId = self::resolveEventId($input);

        return new self([
            'booking_code' => $bookingCode,
            'status' => $status,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'currency' => $currency,
            'amount' => $amount,
            'guest_email' => $guestEmail,
            'guest_phone' => $guestPhone,
            'rooms' => $rooms,
            'adults' => $adults,
            'children' => $children,
            'bucket' => $bucket,
            'raw' => $input,
            'identifiers' => $identifiers,
            'booking_intent_id' => self::cleanString($input['booking_intent_id'] ?? ($input['intent_id'] ?? '')) ?: null,
            'sid' => self::cleanString($input['sid'] ?? ($input['hic_sid'] ?? '')) ?: null,
            'client_ip' => $clientIp,
            'client_user_agent' => $clientUserAgent,
            'event_source_url' => $eventSourceUrl,
            'event_timestamp_micros' => $eventTimestampMicros,
            'event_id' => $eventId,
        ]);
    }

    public function getBookingCode(): string
    {
        return $this->bookingCode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCheckin(): ?string
    {
        return $this->checkin;
    }

    public function getCheckout(): ?string
    {
        return $this->checkout;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getGuestEmail(): ?string
    {
        return $this->guestEmail;
    }

    public function getGuestPhone(): ?string
    {
        return $this->guestPhone;
    }

    public function getRooms(): ?int
    {
        return $this->rooms;
    }

    public function getAdults(): ?int
    {
        return $this->adults;
    }

    public function getChildren(): ?int
    {
        return $this->children;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * @return array<string,mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string,string>
     */
    public function getIdentifiers(): array
    {
        return $this->identifiers;
    }

    public function getBookingIntentId(): ?string
    {
        return $this->bookingIntentId;
    }

    public function getSid(): ?string
    {
        return $this->sid;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function getClientUserAgent(): ?string
    {
        return $this->clientUserAgent;
    }

    public function getEventSourceUrl(): ?string
    {
        return $this->eventSourceUrl;
    }

    public function getEventTimestampMicros(): ?int
    {
        return $this->eventTimestampMicros;
    }

    public function getEventTimestampSeconds(): ?int
    {
        if ($this->eventTimestampMicros === null) {
            return null;
        }

        return (int) floor($this->eventTimestampMicros / 1_000_000);
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function getGuestEmailHash(): string
    {
        $rawHash = $this->raw['guest_email_hash'] ?? null;

        if (is_string($rawHash)) {
            $rawHash = strtolower(trim($rawHash));
            if ($rawHash !== '' && preg_match('/^[a-f0-9]{32,64}$/', $rawHash) === 1) {
                return $rawHash;
            }
        }

        return Hasher::hash($this->guestEmail);
    }

    public function getGuestPhoneHash(): string
    {
        $rawHash = $this->raw['guest_phone_hash'] ?? null;

        if (is_string($rawHash)) {
            $rawHash = strtolower(trim($rawHash));
            if ($rawHash !== '' && preg_match('/^[a-f0-9]{32,64}$/', $rawHash) === 1) {
                return $rawHash;
            }
        }

        return Hasher::hash($this->guestPhone);
    }

    /**
     * @return array<string,mixed>
     */
    public function toDatabaseArray(): array
    {
        return [
            'booking_code' => $this->bookingCode,
            'status' => $this->status,
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'guest_email_hash' => $this->getGuestEmailHash(),
            'guest_phone_hash' => $this->getGuestPhoneHash(),
            'bucket' => $this->bucket,
            'booking_intent_id' => $this->bookingIntentId,
            'sid' => $this->sid,
            'client_ip' => $this->clientIp,
            'client_user_agent' => $this->clientUserAgent,
            'event_timestamp' => $this->eventTimestampMicros,
            'event_id' => $this->eventId,
            'raw_json' => $this->raw,
        ];
    }

    /**
     * @param array<string,string> $identifiers
     * @param array<string,mixed>  $input
     */
    private static function determineBucket(array $identifiers, array $input): string
    {
        if (!empty($identifiers['gclid']) || !empty($identifiers['gbraid']) || !empty($identifiers['wbraid'])) {
            return 'gads';
        }

        if (!empty($identifiers['fbclid'])) {
            return 'fbads';
        }

        if (!empty($input['source']) && is_string($input['source'])) {
            $source = strtolower(trim($input['source']));
            if ($source === 'organic') {
                return 'organic';
            }
        }

        return 'unknown';
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    private static function extractIdentifiers(array $input): array
    {
        $keys = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid'];
        $identifiers = [];

        foreach ($keys as $key) {
            $value = $input[$key] ?? null;

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $identifiers[$key] = self::cleanString($value);
        }

        return $identifiers;
    }

    /**
     * @param array<string,mixed> $input
     */
    private static function resolveClientIp(array $input): ?string
    {
        $ipKeys = ['client_ip', 'client_ip_address', 'ip', 'user_ip', 'forwarded_ip'];

        foreach ($ipKeys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $candidate = $input[$key];

            if (is_array($candidate)) {
                $candidate = reset($candidate);
            }

            $cleaned = self::cleanIp($candidate);

            if ($cleaned !== null) {
                return $cleaned;
            }
        }

        if (!empty($input['x_forwarded_for']) && is_string($input['x_forwarded_for'])) {
            $parts = array_filter(array_map('trim', explode(',', $input['x_forwarded_for'])));

            foreach ($parts as $part) {
                $cleaned = self::cleanIp($part);
                if ($cleaned !== null) {
                    return $cleaned;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     */
    private static function resolveUserAgent(array $input): ?string
    {
        $uaKeys = ['client_user_agent', 'user_agent', 'ua'];

        foreach ($uaKeys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $candidate = $input[$key];

            if (is_array($candidate)) {
                $candidate = reset($candidate);
            }

            $cleaned = self::cleanUserAgent($candidate);

            if ($cleaned !== null) {
                return $cleaned;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     */
    private static function resolveEventSourceUrl(array $input): ?string
    {
        $keys = ['event_source_url', 'source_url', 'page_url'];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            if (is_array($value)) {
                $value = reset($value);
            }

            if (!is_string($value)) {
                continue;
            }

            $url = trim($value);

            if ($url === '') {
                continue;
            }

            if (function_exists('wp_http_validate_url')) {
                $validated = wp_http_validate_url($url);
                if ($validated === false) {
                    continue;
                }

                $url = $validated;
            }

            return $url;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     */
    private static function resolveEventTimestampMicros(array $input): ?int
    {
        $fields = ['timestamp_micros', 'event_timestamp', 'event_time', 'timestamp'];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            if (is_array($value)) {
                $value = reset($value);
            }

            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            if (is_string($value) && !is_numeric($value)) {
                $parsed = self::parseTimestampString($value);

                if ($parsed !== null) {
                    return $parsed;
                }

                continue;
            }

            $numeric = (float) $value;

            if ($numeric <= 0) {
                continue;
            }

            if (stripos($field, 'micros') !== false || $numeric >= 10_000_000_000) {
                return (int) round($numeric);
            }

            return (int) round($numeric * 1_000_000);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     */
    private static function resolveEventId(array $input): ?string
    {
        $keys = ['event_id', 'meta_event_id', 'eventId'];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            if (is_array($value)) {
                $value = reset($value);
            }

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $normalized = preg_replace('/[^A-Za-z0-9_\-:.]/', '', $value);

            if ($normalized === '') {
                continue;
            }

            return substr($normalized, 0, 100);
        }

        return null;
    }

    private static function parseTimestampString(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $formats = [\DateTimeInterface::ATOM, \DateTimeInterface::RFC3339_EXTENDED, \DateTimeInterface::RFC3339];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);

            if ($date instanceof \DateTimeImmutable) {
                return (int) round((float) $date->format('U.u') * 1_000_000);
            }
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return $timestamp > 0 ? $timestamp * 1_000_000 : null;
    }

    private static function cleanString($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        return $value;
    }

    private static function cleanDate($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        if (!$date) {
            return null;
        }

        $errors = \DateTime::getLastErrors();

        if (($errors['warning_count'] ?? 0) > 0) {
            return null;
        }

        if (($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private static function cleanAmount($value): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Invalid amount value');
        }

        $amount = round((float) $value, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero');
        }

        return $amount;
    }

    private static function cleanCurrency($value): string
    {
        $default = 'EUR';

        if (!is_string($value)) {
            return $default;
        }

        $value = strtoupper(trim($value));

        if ($value === '') {
            return $default;
        }

        if (preg_match('/^[A-Z]{3}$/', $value) === 1) {
            return $value;
        }

        return $default;
    }

    private static function cleanEmail($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        return is_email($value) ? $value : null;
    }

    private static function cleanPhone($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = preg_replace('/[^0-9+]/', '', $value) ?? '';

        if ($value === '') {
            return null;
        }

        if (strpos($value, '+') !== 0) {
            if (strpos($value, '00') === 0) {
                $value = '+' . substr($value, 2);
            } else {
                $value = '+' . ltrim($value, '0');
            }
        } else {
            $value = '+' . preg_replace('/\D/', '', substr($value, 1));
        }

        if (preg_match('/^\+(\d{1,3})0(\d{6,})$/', $value, $matches) === 1) {
            $value = '+' . $matches[1] . $matches[2];
        }

        if (!preg_match('/^\+[0-9]{8,15}$/', $value)) {
            return null;
        }

        return $value;
    }

    private static function cleanInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function cleanIp($value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private static function cleanUserAgent($value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (function_exists('wp_check_invalid_utf8')) {
            $value = (string) wp_check_invalid_utf8($value);
        }

        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 512, 'UTF-8');
        } else {
            $value = substr($value, 0, 512);
        }

        return $value;
    }
}
