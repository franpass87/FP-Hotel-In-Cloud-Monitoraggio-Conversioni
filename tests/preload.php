<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        if (\is_object($args)) {
            $args = get_object_vars($args);
        } elseif (\is_string($args)) {
            parse_str($args, $parsed);
            $args = $parsed;
        }

        if (!\is_array($args)) {
            $args = [];
        }

        if (!\is_array($defaults)) {
            $defaults = [];
        }

        return array_merge($defaults, $args);
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string,mixed> */
        private array $params = [];

        /** @var array<string,string> */
        private array $headers = [];

        private ?string $body = null;

        private string $method = 'GET';

        private string $route = '';

        public function __construct($method = 'GET', $route = '', $attributes = [])
        {
            if (\is_array($method)) {
                $this->params = $method;
                $this->method = 'POST';
                $this->route = '';

                if (\is_array($route)) {
                    foreach ($route as $name => $value) {
                        $this->set_header((string) $name, (string) $value);
                    }
                }

                return;
            }

            if (\is_string($method) && $method !== '') {
                $this->method = strtoupper($method);
            }

            if (\is_string($route)) {
                $this->route = $route;
            }

            if (\is_array($attributes)) {
                $this->params = $attributes;
            }
        }

        public function set_param($key, $value): void
        {
            $this->params[(string) $key] = $value;
        }

        public function get_param($key)
        {
            $key = (string) $key;

            return $this->params[$key] ?? null;
        }

        /** @return array<string,mixed> */
        public function get_params(): array
        {
            return $this->params;
        }

        /** @return array<string,mixed> */
        public function get_query_params(): array
        {
            return $this->params;
        }

        public function set_header($key, $value): void
        {
            $this->headers[strtolower((string) $key)] = (string) $value;
        }

        public function get_header($key): string
        {
            $key = strtolower((string) $key);

            return $this->headers[$key] ?? '';
        }

        /** @return array<string,string> */
        public function get_headers(): array
        {
            return $this->headers;
        }

        public function set_body($body): void
        {
            $this->body = (string) $body;
        }

        public function get_body()
        {
            return $this->body;
        }

        public function get_content(): string
        {
            return $this->body ?? '';
        }

        /** @return array<string,mixed> */
        public function get_json_params(): array
        {
            $decoded = json_decode($this->body ?? '', true);

            return \is_array($decoded) ? $decoded : [];
        }

        /** @return array<string,mixed> */
        public function get_body_params(): array
        {
            return $this->get_json_params();
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }
    }
}
// Basic WordPress stubs for autoloaded files
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        if (!isset($GLOBALS['hic_test_hooks'][$hook])) {
            $GLOBALS['hic_test_hooks'][$hook] = [];
        }

        $GLOBALS['hic_test_hooks'][$hook][$priority][] = [
            'function' => $callback,
            'accepted_args' => $accepted_args,
        ];
    }
}
// Basic filter system for testing
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['hic_test_filters'][$hook][$priority][] = [
            'function' => $callback,
            'accepted_args' => $accepted_args
        ];
    }
}
if (!function_exists('has_filter')) {
    function has_filter($hook, $callback = false) {
        if (empty($GLOBALS['hic_test_filters'][$hook])) {
            return false;
        }

        if ($callback === false) {
            return true;
        }

        foreach ($GLOBALS['hic_test_filters'][$hook] as $priority => $callbacks) {
            foreach ($callbacks as $registered) {
                if ($registered['function'] === $callback) {
                    return $priority;
                }
            }
        }

        return false;
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter($hook, $callback, $priority = 10) {
        if (empty($GLOBALS['hic_test_filters'][$hook][$priority])) {
            return false;
        }

        foreach ($GLOBALS['hic_test_filters'][$hook][$priority] as $index => $cb) {
            if ($cb['function'] === $callback) {
                unset($GLOBALS['hic_test_filters'][$hook][$priority][$index]);
            }
        }

        if (empty($GLOBALS['hic_test_filters'][$hook][$priority])) {
            unset($GLOBALS['hic_test_filters'][$hook][$priority]);
        }

        if (empty($GLOBALS['hic_test_filters'][$hook])) {
            unset($GLOBALS['hic_test_filters'][$hook]);
        }

        return true;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        if (empty($GLOBALS['hic_test_filters'][$hook])) {
            return $value;
        }

        ksort($GLOBALS['hic_test_filters'][$hook]);

        foreach ($GLOBALS['hic_test_filters'][$hook] as $callbacks) {
            foreach ($callbacks as $cb) {
                $params = array_merge([$value], array_slice($args, 0, $cb['accepted_args'] - 1));
                $value = \call_user_func_array($cb['function'], $params);
            }
        }

        return $value;
    }
}
if (!function_exists('has_action')) {
    function has_action($hook, $callback = false) {
        if (empty($GLOBALS['hic_test_hooks'][$hook])) {
            return false;
        }

        if ($callback === false) {
            return true;
        }

        foreach ($GLOBALS['hic_test_hooks'][$hook] as $priority => $callbacks) {
            foreach ($callbacks as $registered) {
                if ($registered['function'] === $callback) {
                    return $priority;
                }
            }
        }

        return false;
    }
}
if (!function_exists('register_activation_hook')) { function register_activation_hook(...$args) {} }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook(...$args) {} }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled(...$args) { return false; } }
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
        if (!empty($GLOBALS['wp_schedule_event_invalid'][$recurrence])) {
            return new WP_Error('invalid_schedule', 'Invalid schedule');
        }

        if (!isset($GLOBALS['wp_scheduled_events'])) {
            $GLOBALS['wp_scheduled_events'] = [];
        }

        $GLOBALS['wp_scheduled_events'][] = [
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }
}
if (!function_exists('wp_unschedule_event')) { function wp_unschedule_event(...$args) { return true; } }
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook(...$args) { return true; } }
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(...$args) {
        if (!isset($GLOBALS['wp_scheduled_events'])) {
            $GLOBALS['wp_scheduled_events'] = [];
        }

        if (array_key_exists('hic_test_schedule_single_event_return', $GLOBALS)) {
            $forced = $GLOBALS['hic_test_schedule_single_event_return'];

            if ($forced) {
                $GLOBALS['wp_scheduled_events'][] = $args;
            }

            return $forced;
        }

        $GLOBALS['wp_scheduled_events'][] = $args;
        return true;
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }
}
if (!function_exists('is_multisite')) { function is_multisite() { return false; } }
if (!function_exists('get_sites')) { function get_sites(...$args) { return []; } }
if (!function_exists('switch_to_blog')) {
    function switch_to_blog($site_id)
    {
        if (!isset($GLOBALS['hic_switched_blog'])) {
            $GLOBALS['hic_switched_blog'] = [];
        }

        $GLOBALS['hic_switched_blog'][] = $site_id;
    }
}
if (!function_exists('restore_current_blog')) {
    function restore_current_blog()
    {
        if (!empty($GLOBALS['hic_switched_blog'])) {
            array_pop($GLOBALS['hic_switched_blog']);
        }
    }
}
if (!function_exists('is_admin')) { function is_admin() { return false; } }
if (!function_exists('admin_url')) { function admin_url($path = '') { return $path; } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script(...$args) {} }
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style(...$args) {} }
if (!function_exists('wp_localize_script')) { function wp_localize_script(...$args) {} }
if (!function_exists('wp_add_inline_script')) { function wp_add_inline_script(...$args) {} }
if (!function_exists('add_menu_page')) {
    function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null) {
        global $menu, $submenu;

        if (!is_array($menu ?? null)) {
            $menu = [];
        }

        $hookname = 'toplevel_page_' . $menu_slug;

        $menu[] = [$menu_title, $capability, $menu_slug, $page_title];

        if (!isset($submenu[$menu_slug])) {
            $submenu[$menu_slug] = [
                [$menu_title, $capability, $menu_slug, $page_title],
            ];
        }

        if (!isset($GLOBALS['hic_registered_menu_pages'])) {
            $GLOBALS['hic_registered_menu_pages'] = [];
        }

        $GLOBALS['hic_registered_menu_pages'][$menu_slug] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug'  => $menu_slug,
            'callback'   => $callback,
            'icon_url'   => $icon_url,
            'position'   => $position,
            'hookname'   => $hookname,
        ];

        return $hookname;
    }
}
if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null) {
        global $submenu;

        if (!is_array($submenu ?? null)) {
            $submenu = [];
        }

        if (!isset($submenu[$parent_slug])) {
            $submenu[$parent_slug] = [];
        }

        $entry = [$menu_title, $capability, $menu_slug, $page_title];

        if ($position === null) {
            $submenu[$parent_slug][] = $entry;
        } else {
            $submenu[$parent_slug][$position] = $entry;
            ksort($submenu[$parent_slug]);
            $submenu[$parent_slug] = array_values($submenu[$parent_slug]);
        }

        if (!isset($GLOBALS['hic_registered_submenus'])) {
            $GLOBALS['hic_registered_submenus'] = [];
        }

        $hookname = $parent_slug . '_page_' . $menu_slug;

        $GLOBALS['hic_registered_submenus'][$parent_slug][$menu_slug] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug'  => $menu_slug,
            'callback'   => $callback,
            'position'   => $position,
            'hookname'   => $hookname,
        ];

        return $hookname;
    }
}
if (!class_exists('WP_Role')) {
    class WP_Role {
        /** @var string */
        public $name;

        /** @var array<string,bool> */
        public $capabilities = [];

        /**
         * @param array<string,bool> $capabilities
         */
        public function __construct(string $name, array $capabilities = [])
        {
            $this->name = $name;

            foreach ($capabilities as $capability => $grant) {
                if ($grant) {
                    $this->capabilities[$capability] = true;
                }
            }
        }

        public function has_cap(string $cap): bool
        {
            return !empty($this->capabilities[$cap]);
        }

        public function add_cap(string $cap): void
        {
            $this->capabilities[$cap] = true;
        }

        public function remove_cap(string $cap): void
        {
            unset($this->capabilities[$cap]);
        }
    }
}

if (!class_exists('WP_Roles')) {
    class WP_Roles {
        /** @var array<string,array<string,mixed>> */
        public $roles = [];

        /** @var array<string,WP_Role> */
        public $role_objects = [];

        public function __construct()
        {
            $this->add_role('administrator', [
                'read'     => true,
                'level_10' => true,
            ]);
        }

        /**
         * @param array<string,bool> $capabilities
         */
        public function add_role(string $role, array $capabilities = []): void
        {
            $this->roles[$role] = [
                'capabilities' => $capabilities,
            ];

            $this->role_objects[$role] = new WP_Role($role, $capabilities);
        }

        public function get_role(string $role): ?WP_Role
        {
            return $this->role_objects[$role] ?? null;
        }
    }
}

if (!function_exists('wp_roles')) {
    function wp_roles(): WP_Roles
    {
        global $wp_roles;

        if (!($wp_roles instanceof WP_Roles)) {
            $wp_roles = new WP_Roles();
        }

        return $wp_roles;
    }
}

if (!function_exists('get_role')) {
    function get_role(string $role): ?WP_Role
    {
        return wp_roles()->get_role($role);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        if (empty($GLOBALS['hic_test_hooks'][$hook])) {
            return;
        }

        ksort($GLOBALS['hic_test_hooks'][$hook]);

        foreach ($GLOBALS['hic_test_hooks'][$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $params = array_slice($args, 0, $callback['accepted_args']);
                \call_user_func_array($callback['function'], $params);
            }
        }
    }
}
if (!function_exists('wp_doing_cron')) { function wp_doing_cron() { return defined('HIC_TEST_DOING_CRON') && HIC_TEST_DOING_CRON; } }
if (!function_exists('is_ssl')) { function is_ssl() { return false; } }
if (!function_exists('home_url')) {
    function home_url($path = '') {
        if (!is_string($path)) {
            $path = '';
        }

        return 'https://example.com' . $path;
    }
}
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return $thing instanceof \WP_Error; } }
if (!function_exists('wp_error')) { function wp_error($code = '', $message = '', $data = null) { return new \WP_Error($code, $message, $data); } }
if (!isset($GLOBALS['hic_test_transients'])) {
    $GLOBALS['hic_test_transients'] = [];
    $GLOBALS['hic_test_transient_expirations'] = [];
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $hic_test_transients, $hic_test_transient_expirations;

        if (!is_array($hic_test_transients)) {
            $hic_test_transients = [];
        }

        if (!is_array($hic_test_transient_expirations)) {
            $hic_test_transient_expirations = [];
        }

        if (!array_key_exists($transient, $hic_test_transients)) {
            return false;
        }

        $expires = $hic_test_transient_expirations[$transient] ?? 0;
        if (!empty($expires) && $expires > 0 && $expires < time()) {
            unset($hic_test_transients[$transient], $hic_test_transient_expirations[$transient]);
            return false;
        }

        return $hic_test_transients[$transient];
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        global $hic_test_transients, $hic_test_transient_expirations;

        if (!is_array($hic_test_transients)) {
            $hic_test_transients = [];
        }

        if (!is_array($hic_test_transient_expirations)) {
            $hic_test_transient_expirations = [];
        }

        $hic_test_transients[$transient] = $value;
        $hic_test_transient_expirations[$transient] = $expiration > 0 ? (time() + (int) $expiration) : 0;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $hic_test_transients, $hic_test_transient_expirations;

        if (is_array($hic_test_transients) && array_key_exists($transient, $hic_test_transients)) {
            unset($hic_test_transients[$transient]);
        }

        if (is_array($hic_test_transient_expirations) && array_key_exists($transient, $hic_test_transient_expirations)) {
            unset($hic_test_transient_expirations[$transient]);
        }

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $hic_test_options, $hic_test_option_autoload;

        unset($hic_test_options[$option]);

        if (is_array($hic_test_option_autoload ?? null)) {
            unset($hic_test_option_autoload[$option]);
        }

        if (function_exists('do_action')) {
            do_action('delete_option', $option);
            do_action('deleted_option', $option);
        }

        return true;
    }
}
if (!function_exists('wp_upload_dir')) { function wp_upload_dir($path = null) { return ['basedir' => sys_get_temp_dir(), 'baseurl' => '']; } }
if (!function_exists('plugin_basename')) { function plugin_basename($file) { return $file; } }
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        if (!is_scalar($str)) {
            return '';
        }

        $value = (string) $str;
        $value = strip_tags($value);
        $value = preg_replace('/[\r\n\t\0\x0B]+/', '', $value);
        if ($value === null) {
            $value = '';
        }

        return trim($value);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        if (!is_scalar($key)) {
            return '';
        }

        $key = strtolower((string) $key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key);

        return $key ?? '';
    }
}
if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        return date($format, $timestamp ?? time());
    }
}
if (!function_exists('esc_sql')) {
    function esc_sql($sql) {
        return $sql;
    }
}
if (!function_exists('__')) {
    function __($text, $domain = null) {
        return is_scalar($text) ? (string) $text : '';
    }
}
if (!function_exists('_e')) {
    function _e($text, $domain = null) {
        echo is_scalar($text) ? (string) $text : '';
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) {
        return htmlspecialchars(is_scalar($text) ? (string) $text : '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null) {
        echo esc_html__($text, $domain);
    }
}
