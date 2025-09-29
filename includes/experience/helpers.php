<?php declare(strict_types=1);

namespace FP_Exp\Utils;

use function FpHic\Helpers\hic_is_debug_verbose;
use function FpHic\Helpers\hic_log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper utilities for the FP Experience front-end bindings.
 */
final class Helpers
{
    /** @var array<int, array<string, bool>> */
    private static $missingMetaLogged = [];

    /**
     * Retrieve a post meta value and guarantee an array output.
     *
     * The helper normalises scalar strings, JSON blobs and mixed arrays
     * returning a clean list that templates can consume safely.
     */
    public static function get_meta_array(int $postId, string $key): array
    {
        if ($postId <= 0 || $key === '') {
            return [];
        }

        $value = get_post_meta($postId, $key, true);

        if (empty($value)) {
            self::log_missing_meta($postId, $key);
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $lines = preg_split('/\r\n|\r|\n/', $value);
                if (is_array($lines)) {
                    $value = array_filter(array_map('trim', $lines), static function ($item): bool {
                        return $item !== '';
                    });
                }
            }
        }

        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value, true);
        }

        if (is_scalar($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $normalised = [];

        foreach ($value as $item) {
            if ($item === null || $item === '') {
                continue;
            }

            if (is_scalar($item)) {
                $normalised[] = (string) $item;
                continue;
            }

            if (is_array($item)) {
                $normalised[] = $item;
            }
        }

        if (empty($normalised)) {
            self::log_missing_meta($postId, $key);
        }

        return $normalised;
    }

    private static function log_missing_meta(int $postId, string $key): void
    {
        if (!hic_is_debug_verbose()) {
            return;
        }

        if (!isset(self::$missingMetaLogged[$postId][$key])) {
            hic_log(
                sprintf('[FP_Exp] Meta "%s" non trovato per esperienza #%d', $key, $postId),
                HIC_LOG_LEVEL_DEBUG,
                [
                    'post_id' => $postId,
                    'meta_key' => $key,
                ]
            );

            self::$missingMetaLogged[$postId][$key] = true;
        }
    }
}
