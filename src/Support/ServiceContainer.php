<?php declare(strict_types=1);

namespace FpHic\HicS2S\Support;

use FpHic\HicS2S\Repository\BookingIntents;
use FpHic\HicS2S\Repository\Conversions;
use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Services\Ga4Service;
use FpHic\HicS2S\Services\MetaCapiService;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceContainer
{
    private static ?self $instance = null;

    /** @var array<string,object> */
    private array $services = [];

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new \LogicException('ServiceContainer cannot be unserialized');
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function flush(): void
    {
        if (self::$instance === null) {
            return;
        }

        self::$instance->services = [];
    }

    public function logs(): Logs
    {
        if (!isset($this->services['logs'])) {
            $this->services['logs'] = new Logs();
        }

        return $this->services['logs'];
    }

    public function conversions(): Conversions
    {
        if (!isset($this->services['conversions'])) {
            $this->services['conversions'] = new Conversions($this->logs());
        }

        return $this->services['conversions'];
    }

    public function bookingIntents(): BookingIntents
    {
        if (!isset($this->services['booking_intents'])) {
            $this->services['booking_intents'] = new BookingIntents($this->logs());
        }

        return $this->services['booking_intents'];
    }

    public function ga4Service(): Ga4Service
    {
        if (!isset($this->services['ga4_service'])) {
            $this->services['ga4_service'] = new Ga4Service($this->logs());
        }

        return $this->services['ga4_service'];
    }

    public function metaService(): MetaCapiService
    {
        if (!isset($this->services['meta_service'])) {
            $this->services['meta_service'] = new MetaCapiService($this->logs());
        }

        return $this->services['meta_service'];
    }
}
