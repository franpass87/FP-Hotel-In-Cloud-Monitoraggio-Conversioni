<?php

use FpHic\HicS2S\ValueObjects\BookingPayload;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Support/Hasher.php';
require_once __DIR__ . '/../src/ValueObjects/BookingPayload.php';

final class BookingPayloadValueObjectTest extends TestCase
{
    public function testRejectsNonNumericAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingPayload::fromArray([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => 'EUR',
            'amount' => 'foo',
        ]);
    }

    public function testRejectsZeroAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingPayload::fromArray([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => 'EUR',
            'amount' => 0,
        ]);
    }

    public function testNormalizesCurrencyAndAmount(): void
    {
        $payload = BookingPayload::fromArray([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => 'usd',
            'amount' => '123.456',
        ]);

        $this->assertSame('USD', $payload->getCurrency());
        $this->assertSame(123.46, $payload->getAmount());
    }

    public function testInvalidCurrencyFallsBackToDefault(): void
    {
        $payload = BookingPayload::fromArray([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => '1',
            'amount' => 50,
        ]);

        $this->assertSame('EUR', $payload->getCurrency());
    }

    public function testNormalizesPhoneNumberToE164(): void
    {
        $payload = BookingPayload::fromArray([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => 'EUR',
            'amount' => 100,
            'guest_phone' => '0039 0551234567',
        ]);

        $this->assertSame('+39551234567', $payload->getGuestPhone());
    }

    public function testParsesIsoTimestampIntoMicros(): void
    {
        $payload = BookingPayload::fromArray([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => 'EUR',
            'amount' => 100,
            'event_time' => '2024-05-20T14:30:00Z',
        ]);

        $expectedSeconds = strtotime('2024-05-20T14:30:00Z');
        $this->assertNotFalse($expectedSeconds);

        $this->assertSame($expectedSeconds, $payload->getEventTimestampSeconds());
        $this->assertSame($expectedSeconds * 1_000_000, $payload->getEventTimestampMicros());
    }

    public function testKeepsMultibyteUserAgent(): void
    {
        $payload = BookingPayload::fromArray([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => 'EUR',
            'amount' => 100,
            'client_user_agent' => 'Mozilla/5.0 🚀 RocketBrowser',
        ]);

        $this->assertSame('Mozilla/5.0 🚀 RocketBrowser', $payload->getClientUserAgent());
    }

    public function testUsesProvidedEventId(): void
    {
        $payload = BookingPayload::fromArray([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => 'EUR',
            'amount' => 100,
            'event_id' => 'event-123-XYZ',
        ]);

        $this->assertSame('event-123-XYZ', $payload->getEventId());
    }
}
