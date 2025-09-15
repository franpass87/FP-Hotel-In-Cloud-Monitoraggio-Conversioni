<?php declare(strict_types=1);
/**
 * HTTP Security Enhancement for HIC Plugin
 * 
 * Provides secure HTTP request handling with proper validation,
 * timeout management, and error handling.
 */

namespace FpHic;

use function FpHic\Helpers\hic_log;

if (!defined('ABSPATH')) exit;

class HIC_HTTP_Security {
    
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_REDIRECTS = 3;
    private const MAX_RESPONSE_SIZE = 10485760; // 10MB
    
    /**
     * Secure HTTP GET request with enhanced validation
     */
    public static function secure_get($url, $args = []) {
        return self::secure_request('GET', $url, $args);
    }
    
    /**
     * Secure HTTP POST request with enhanced validation
     */
    public static function secure_post($url, $args = []) {
        return self::secure_request('POST', $url, $args);
    }
    
    /**
     * Enhanced secure HTTP request with comprehensive validation
     */
    private static function secure_request($method, $url, $args = []) {
        // Validate URL
        if (!self::validate_url($url)) {
            return new \WP_Error('invalid_url', 'URL non valido o non sicuro');
        }
        
        // Prepare secure arguments
        $secure_args = self::prepare_secure_args($method, $args);
        
        // Log request attempt
        hic_log("HTTP $method request to: " . self::sanitize_url_for_log($url));
        
        // Make request based on method
        $response = ($method === 'POST') 
            ? wp_remote_post($url, $secure_args)
            : wp_remote_get($url, $secure_args);
        
        // Enhanced response validation
        return self::validate_response($response, $url);
    }
    
    /**
     * Validate URL for security
     */
    private static function validate_url($url) {
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $parsed = parse_url($url);
        if (!$parsed) {
            return false;
        }
        
        // Ensure HTTPS for external APIs
        if (isset($parsed['scheme']) && $parsed['scheme'] !== 'https') {
            hic_log('Warning: Non-HTTPS URL detected: ' . self::sanitize_url_for_log($url), HIC_LOG_LEVEL_WARNING);
        }
        
        // Block suspicious hosts
        if (isset($parsed['host'])) {
            $blocked_hosts = ['localhost', '127.0.0.1', '0.0.0.0'];
            if (in_array($parsed['host'], $blocked_hosts, true)) {
                hic_log('Blocked request to suspicious host: ' . $parsed['host'], HIC_LOG_LEVEL_WARNING);
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Prepare secure request arguments
     */
    private static function prepare_secure_args($method, $args) {
        $defaults = [
            'timeout' => self::DEFAULT_TIMEOUT,
            'redirection' => self::MAX_REDIRECTS,
            'user-agent' => self::get_secure_user_agent(),
            'headers' => [],
            'sslverify' => true,
            'compress' => true,
            'decompress' => true,
        ];
        
        $secure_args = wp_parse_args($args, $defaults);
        
        // Enforce security limits
        $secure_args['timeout'] = min($secure_args['timeout'], 60); // Max 60 seconds
        $secure_args['redirection'] = min($secure_args['redirection'], self::MAX_REDIRECTS);
        
        // Ensure SSL verification
        $secure_args['sslverify'] = true;
        
        // Add security headers
        if (!isset($secure_args['headers']['Accept'])) {
            $secure_args['headers']['Accept'] = 'application/json';
        }
        
        return $secure_args;
    }
    
    /**
     * Validate HTTP response with security checks
     */
    private static function validate_response($response, $url) {
        // Check for WP_Error
        if (is_wp_error($response)) {
            hic_log('HTTP request failed: ' . $response->get_error_message(), HIC_LOG_LEVEL_ERROR);
            return $response;
        }
        
        // Validate response structure
        if (!is_array($response)) {
            return new \WP_Error('invalid_response', 'Risposta HTTP non valida');
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Check response size
        if (strlen($response_body) > self::MAX_RESPONSE_SIZE) {
            hic_log('Response troppo grande: ' . strlen($response_body) . ' bytes', HIC_LOG_LEVEL_WARNING);
            return new \WP_Error('response_too_large', 'Risposta troppo grande');
        }
        
        // Check HTTP status
        if ($response_code < 200 || $response_code >= 400) {
            $error_msg = "HTTP $response_code per " . self::sanitize_url_for_log($url);
            hic_log($error_msg, HIC_LOG_LEVEL_ERROR);
            
            // Return specific error based on status
            return self::create_http_error($response_code, $response_body);
        }
        
        // Log successful response
        hic_log("HTTP request successful: $response_code per " . self::sanitize_url_for_log($url));
        
        return $response;
    }
    
    /**
     * Create appropriate WP_Error for HTTP status codes
     */
    private static function create_http_error($code, $body) {
        switch ($code) {
            case 401:
                return new \WP_Error('unauthorized', 'Credenziali non valide', ['status' => $code]);
            case 403:
                return new \WP_Error('forbidden', 'Accesso negato', ['status' => $code]);
            case 404:
                return new \WP_Error('not_found', 'Risorsa non trovata', ['status' => $code]);
            case 429:
                return new \WP_Error('rate_limited', 'Troppi richieste - rate limit', ['status' => $code]);
            case 500:
                return new \WP_Error('server_error', 'Errore del server', ['status' => $code]);
            default:
                return new \WP_Error('http_error', "Errore HTTP $code", ['status' => $code, 'body' => $body]);
        }
    }
    
    /**
     * Get secure user agent string
     */
    private static function get_secure_user_agent() {
        return sprintf(
            'WordPress/%s; HIC-Plugin/%s; %s',
            get_bloginfo('version'),
            HIC_PLUGIN_VERSION,
            home_url()
        );
    }
    
    /**
     * Sanitize URL for logging (remove sensitive parameters)
     */
    private static function sanitize_url_for_log($url) {
        $parsed = parse_url($url);
        if (!$parsed) {
            return '[invalid URL]';
        }
        
        // Remove query parameters that might contain sensitive data
        return $parsed['scheme'] . '://' . $parsed['host'] . ($parsed['path'] ?? '') . '[params hidden]';
    }
}