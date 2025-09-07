<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

use FpHic\Helpers;

class MockWpdb
{
    public $prefix = 'wp_';
    public $last_error = '';
    private $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
    }

    public function prepare($query, $value)
    {
        return str_replace('%s', $this->pdo->quote($value), $query);
    }

    public function exec($sql)
    {
        $this->pdo->exec($sql);
    }

    public function get_var($query)
    {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $m)) {
            $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=".$this->pdo->quote($m[1]));
            return $stmt->fetchColumn() ?: null;
        }
        $stmt = $this->pdo->query($query);
        if (!$stmt) {
            $this->last_error = implode(' ', $this->pdo->errorInfo());
            return null;
        }
        return $stmt->fetchColumn();
    }

    public function get_row($query)
    {
        $stmt = $this->pdo->query($query);
        if (!$stmt) {
            $this->last_error = implode(' ', $this->pdo->errorInfo());
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }
}

final class TrackingIdsTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new MockWpdb();
        $wpdb->exec("CREATE TABLE wp_hic_gclids (id INTEGER PRIMARY KEY AUTOINCREMENT, gclid TEXT, fbclid TEXT, msclkid TEXT, ttclid TEXT, sid TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT);");
        $wpdb->exec("INSERT INTO wp_hic_gclids (gclid, fbclid, msclkid, ttclid, sid) VALUES ('g1', 'f1', 'm1', 't1', 'SID123');");
        $wpdb->exec("INSERT INTO wp_hic_gclids (sid, utm_source, utm_medium, utm_campaign, utm_content, utm_term) VALUES ('SIDUTM', 'google', 'cpc', 'camp', 'content', 'term');");
    }

    public function testRetrievesTrackingIds()
    {
        $result = Helpers\hic_get_tracking_ids_by_sid('SID123');
        $this->assertSame(['gclid' => 'g1', 'fbclid' => 'f1', 'msclkid' => 'm1', 'ttclid' => 't1'], $result);
    }

    public function testSanitizesSid()
    {
        $result = Helpers\hic_get_tracking_ids_by_sid('<script>SID123</script>');
        $this->assertSame(['gclid' => 'g1', 'fbclid' => 'f1', 'msclkid' => 'm1', 'ttclid' => 't1'], $result);
    }

    public function testReturnsNullWhenNotFound()
    {
        $result = Helpers\hic_get_tracking_ids_by_sid('UNKNOWN');
        $this->assertNull($result['gclid']);
        $this->assertNull($result['fbclid']);
        $this->assertNull($result['msclkid']);
        $this->assertNull($result['ttclid']);
    }

    public function testRetrievesUtmParams()
    {
        $result = Helpers\hic_get_utm_params_by_sid('SIDUTM');
        $this->assertSame([
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'camp',
            'utm_content'  => 'content',
            'utm_term'     => 'term',
        ], $result);
    }
}
