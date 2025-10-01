<?php

declare(strict_types=1);

use FpHic\HicS2S\Repository\Conversions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Repository/Conversions.php';
require_once __DIR__ . '/../src/Repository/Logs.php';

final class ConversionsInsertNormalizationTest extends TestCase
{
    private ConversionsInsertNormalizationTestWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = new ConversionsInsertNormalizationTestWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testIntegerIdentifiersAreStoredAsStrings(): void
    {
        $repository = new Conversions();

        $repository->insert([
            'booking_code' => 'ABC123',
            'event_id' => 987654321,
            'booking_intent_id' => 123456,
        ]);

        $this->assertNotEmpty($this->wpdb->insertedRows);
        $row = $this->wpdb->insertedRows[0];

        $this->assertSame('987654321', $row['event_id']);
        $this->assertSame('123456', $row['booking_intent_id']);
    }

    public function testNullEventTimestampIsNotConvertedToZero(): void
    {
        $repository = new Conversions();

        $repository->insert([
            'booking_code' => 'DEF456',
            'event_timestamp' => null,
        ]);

        $this->assertNotEmpty($this->wpdb->insertedRows);
        $row = $this->wpdb->insertedRows[0];

        $this->assertArrayNotHasKey('event_timestamp', $row);
    }
}

final class ConversionsInsertNormalizationTestWpdb
{
    public string $prefix = 'wp_';

    public int $insert_id = 0;

    /** @var array<int,array<string,mixed>> */
    public array $insertedRows = [];

    public function get_charset_collate(): string
    {
        return 'utf8mb4_unicode_ci';
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>|string $format
     */
    public function insert(string $table, array $data, $format)
    {
        $this->insert_id++;
        $this->insertedRows[] = $data;

        return 1;
    }

    public function prepare(string $query, ...$args)
    {
        if ($args === []) {
            return $query;
        }

        foreach ($args as &$arg) {
            if (is_string($arg)) {
                $arg = addslashes($arg);
            }
        }

        return vsprintf($query, $args);
    }

    public function query($query)
    {
        return 0;
    }

    public function get_var($query)
    {
        return 0;
    }

    public function get_row($query, $output = 'ARRAY_A')
    {
        return null;
    }

    public function get_results($query, $output = 'ARRAY_A')
    {
        return [];
    }
}
