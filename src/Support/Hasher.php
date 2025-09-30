<?php declare(strict_types=1);

namespace FpHic\HicS2S\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Hasher
{
    public static function hash(?string $value): string
    {
        $value = is_string($value) ? trim(strtolower($value)) : '';

        if ($value === '') {
            return '';
        }

        return hash('sha256', $value);
    }
}
