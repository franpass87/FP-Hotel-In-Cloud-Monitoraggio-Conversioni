<?php

namespace {

use PHPUnit\Framework\TestCase;

if (!function_exists('did_action')) {
    function did_action($hook) {
        return $GLOBALS['hic_did_actions'][$hook] ?? 0;
    }
}

if (!isset($GLOBALS['hic_test_hooks'])) {
    $GLOBALS['hic_test_hooks'] = [];
}

if (!isset($GLOBALS['hic_test_filters'])) {
    $GLOBALS['hic_test_filters'] = [];
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers-scheduling.php';
require_once __DIR__ . '/../includes/automated-reporting.php';
require_once __DIR__ . '/../includes/database-optimizer.php';

final class CronScheduleRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        global $wp_scheduled_events, $wp_schedule_event_invalid, $hic_test_options, $hic_test_hooks, $hic_test_filters, $hic_did_actions;

        $wp_scheduled_events = [];
        $wp_schedule_event_invalid = [];
        $hic_test_options = [];
        $hic_test_hooks = [];
        $hic_test_filters = [];
        $hic_did_actions = [];
    }

    protected function tearDown(): void
    {
        $managerReflection = new \ReflectionClass(\FpHic\AutomatedReporting\AutomatedReportingManager::class);
        if ($managerReflection->hasProperty('instance')) {
            $property = $managerReflection->getProperty('instance');
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }

    public function test_custom_schedules_include_weekly_and_monthly(): void
    {
        $schedules = \FpHic\Helpers\hic_add_failed_request_schedule([]);

        $this->assertArrayHasKey('hic_every_fifteen_minutes', $schedules);
        $this->assertArrayHasKey('weekly', $schedules);
        $this->assertSame(7 * 24 * 60 * 60, $schedules['weekly']['interval']);
        $this->assertArrayHasKey('monthly', $schedules);
        $this->assertSame(30 * 24 * 60 * 60, $schedules['monthly']['interval']);
    }

    public function test_weekly_and_monthly_events_are_scheduled(): void
    {
        global $wp_scheduled_events;

        \FpHic\Helpers\hic_init_helper_hooks();

        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();
        $managerReflection = new \ReflectionClass($manager);

        $scheduleReports = $managerReflection->getMethod('schedule_automatic_reports');
        $scheduleReports->setAccessible(true);
        $scheduleReports->invoke($manager);

        $scheduleCleanup = $managerReflection->getMethod('schedule_export_cleanup');
        $scheduleCleanup->setAccessible(true);
        $scheduleCleanup->invoke($manager);

        $optimizer = new \FpHic\DatabaseOptimizer\DatabaseOptimizer();
        $optimizer->schedule_optimization_tasks();

        $scheduled = [];
        foreach ($wp_scheduled_events as $event) {
            $scheduled[$event['hook']] = $event['recurrence'];
        }

        $this->assertArrayHasKey('hic_weekly_report', $scheduled);
        $this->assertSame('weekly', $scheduled['hic_weekly_report']);

        $this->assertArrayHasKey('hic_monthly_report', $scheduled);
        $this->assertSame('monthly', $scheduled['hic_monthly_report']);

        $this->assertArrayHasKey('hic_cleanup_exports', $scheduled);
        $this->assertSame('weekly', $scheduled['hic_cleanup_exports']);

        $this->assertArrayHasKey('hic_weekly_database_optimization', $scheduled);
        $this->assertSame('weekly', $scheduled['hic_weekly_database_optimization']);
    }
}

}

