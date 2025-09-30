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
        $currency = strtoupper(substr(self::cleanString($input['currency'] ?? ''), 0, 3));
        $amount = self::cleanAmount($input['amount'] ?? 0);
        $guestEmail = self::cleanEmail($input['guest_email'] ?? null);
        $guestPhone = self::cleanPhone($input['guest_phone'] ?? null);

        $rooms = self::cleanInt($input['rooms'] ?? null);
        $adults = self::cleanInt($input['adults'] ?? null);
        $children = self::cleanInt($input['children'] ?? null);

        $identifiers = self::extractIdentifiers($input);

        $bucket = self::determineBucket($identifiers, $input);

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

    public function getGuestEmailHash(): string
    {
        return Hasher::hash($this->guestEmail);
    }

    public function getGuestPhoneHash(): string
    {
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

        $date = date_create($value);

        if (!$date) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private static function cleanAmount($value): float
    {
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        return 0.0;
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

        $value = preg_replace('/[^0-9+]/', '', $value ?? '') ?? '';

        if ($value === '') {
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
}
