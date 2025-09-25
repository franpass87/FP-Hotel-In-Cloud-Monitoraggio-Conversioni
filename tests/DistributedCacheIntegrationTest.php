<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FpHic\HIC_Cache_Manager;

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool)($GLOBALS['hic_test_is_multisite'] ?? false);
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id()
    {
        return $GLOBALS['hic_test_blog_id'] ?? 1;
    }
}

if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache(): bool
    {
        return true;
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '')
    {
        return $GLOBALS['hic_test_object_cache'][$group][$key] ?? false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $value, $group = '', $ttl = 0)
    {
        if (!isset($GLOBALS['hic_test_object_cache'][$group])) {
            $GLOBALS['hic_test_object_cache'][$group] = [];
        }
        $GLOBALS['hic_test_object_cache'][$group][$key] = $value;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '')
    {
        unset($GLOBALS['hic_test_object_cache'][$group][$key]);
        return true;
    }
}

final class DistributedCacheIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/cache-manager.php';
        $GLOBALS['hic_test_object_cache'] = [];
        $GLOBALS['hic_test_is_multisite'] = false;
        $GLOBALS['hic_test_blog_id'] = 1;
        delete_option('hic_cache_tracked_keys');

        $ref = new ReflectionClass(HIC_Cache_Manager::class);

        foreach (['memory_cache' => [], 'tracked_keys' => [], 'namespace_cache' => []] as $property => $value) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }

        $trackedLoaded = $ref->getProperty('tracked_loaded');
        $trackedLoaded->setAccessible(true);
        $trackedLoaded->setValue(null, false);
    }

    public function testObjectCacheLayerPersistsEntriesAndTracking(): void
    {
        $payload = ['foo' => 'bar'];
        $this->assertTrue(HIC_Cache_Manager::set('distributed', $payload, 120));

        $ref = new ReflectionClass(HIC_Cache_Manager::class);
        $method = $ref->getMethod('get_cache_key');
        $method->setAccessible(true);
        $cacheKey = $method->invoke(null, 'distributed');

        $namespaceMethod = $ref->getMethod('get_cache_namespace');
        $namespaceMethod->setAccessible(true);
        $namespace = $namespaceMethod->invoke(null);

        $this->assertNotFalse(wp_cache_get($cacheKey, 'hic_cache'));

        $memoryProperty = $ref->getProperty('memory_cache');
        $memoryProperty->setAccessible(true);
        $memoryProperty->setValue(null, []);

        $fetched = HIC_Cache_Manager::get('distributed');
        $this->assertSame($payload, $fetched);

        $tracked = get_option('hic_cache_tracked_keys', []);
        $this->assertIsArray($tracked);
        $this->assertArrayHasKey($namespace, $tracked);
        $this->assertContains($cacheKey, $tracked[$namespace]);

        HIC_Cache_Manager::delete('distributed');
        $this->assertFalse(wp_cache_get($cacheKey, 'hic_cache'));
        $trackedAfterDelete = get_option('hic_cache_tracked_keys', []);
        if (isset($trackedAfterDelete[$namespace])) {
            $this->assertNotContains($cacheKey, $trackedAfterDelete[$namespace]);
        }
    }

    public function testCacheKeysAreIsolatedPerBlog(): void
    {
        $ref = new ReflectionClass(HIC_Cache_Manager::class);
        $getKey = $ref->getMethod('get_cache_key');
        $getKey->setAccessible(true);
        $getNamespace = $ref->getMethod('get_cache_namespace');
        $getNamespace->setAccessible(true);

        $GLOBALS['hic_test_blog_id'] = 5;
        $keyBlogFive = $getKey->invoke(null, 'shared');
        $namespaceFive = $getNamespace->invoke(null);

        $GLOBALS['hic_test_blog_id'] = 9;
        $keyBlogNine = $getKey->invoke(null, 'shared');
        $namespaceNine = $getNamespace->invoke(null);

        $this->assertNotSame($namespaceFive, $namespaceNine);
        $this->assertNotSame($keyBlogFive, $keyBlogNine);

        HIC_Cache_Manager::set('shared', 'value-nine', 60);

        $tracked = get_option('hic_cache_tracked_keys', []);
        $this->assertIsArray($tracked);
        $this->assertArrayHasKey($namespaceNine, $tracked);
        $this->assertContains($keyBlogNine, $tracked[$namespaceNine]);
        $this->assertArrayNotHasKey($namespaceFive, $tracked, 'Namespaces should be isolated between blogs.');
    }
}
