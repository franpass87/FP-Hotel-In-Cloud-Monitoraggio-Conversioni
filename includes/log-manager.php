<?php declare(strict_types=1);
/**
 * Enhanced Log Management System for HIC Plugin
 * 
 * Provides improved logging with rotation, levels, and better management.
 */

if (!defined('ABSPATH')) exit;

class HIC_Log_Manager {
    
    private $log_file;
    private $max_size;
    private $retention_days;
    private $log_level;
    
    public function __construct() {
        // Ensure WordPress functions are available
        if (!function_exists('hic_get_log_file')) {
            return;
        }
        
        $this->log_file = hic_get_log_file();
        $this->max_size = HIC_LOG_MAX_SIZE;
        $this->retention_days = apply_filters( 'hic_log_retention_days', HIC_LOG_RETENTION_DAYS );
        $this->log_level = function_exists('hic_get_option') ? hic_get_option('log_level', HIC_LOG_LEVEL_INFO) : HIC_LOG_LEVEL_INFO;
        
        // Hook into WordPress shutdown to clean up logs (only if add_action exists)
        if (function_exists('add_action')) {
            add_action('shutdown', [$this, 'cleanup_old_logs']);
        }
    }
    
    /**
     * Enhanced logging function with levels and rotation
     */
    public function log($message, $level = HIC_LOG_LEVEL_INFO, $context = []) {
        // Skip logging if disabled or log file not configured
        if (empty($this->log_file)) {
            return false;
        }
        
        // Skip if below configured level
        if (!$this->should_log($level)) {
            return false;
        }

        // Rotate log if needed
        $this->rotate_if_needed();

        /**
         * Filters the log message before it is formatted and written.
         *
         * Developers can use this hook to modify or sanitize log messages.
         * The default implementation applies the {@see hic_mask_sensitive_data}
         * helper to hide common sensitive information.
         *
         * @param string $message Original log message.
         * @param string $level   Log level for the message.
         */
        $message = apply_filters('hic_log_message', $message, $level);

        // Format log entry
        $formatted_message = $this->format_log_entry($message, $level, $context);
        
        // Write to log file
        return $this->write_to_log($formatted_message);
    }

    private function should_log($level) {
        $levels = [
            HIC_LOG_LEVEL_ERROR   => 0,
            HIC_LOG_LEVEL_WARNING => 1,
            HIC_LOG_LEVEL_INFO    => 2,
            HIC_LOG_LEVEL_DEBUG   => 3,
        ];

        $current = $levels[$this->log_level] ?? $levels[HIC_LOG_LEVEL_INFO];
        return ($levels[$level] ?? $levels[HIC_LOG_LEVEL_INFO]) <= $current;
    }
    
    /**
     * Log error message
     */
    public function error($message, $context = []) {
        return $this->log($message, HIC_LOG_LEVEL_ERROR, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $context = []) {
        return $this->log($message, HIC_LOG_LEVEL_WARNING, $context);
    }
    
    /**
     * Log info message
     */
    public function info($message, $context = []) {
        return $this->log($message, HIC_LOG_LEVEL_INFO, $context);
    }
    
    /**
     * Log debug message (only if debug mode enabled)
     */
    public function debug($message, $context = []) {
        if (Helpers\hic_is_debug_verbose()) {
            return $this->log($message, HIC_LOG_LEVEL_DEBUG, $context);
        }
        return true;
    }
    
    /**
     * Format log entry
     */
    private function format_log_entry($message, $level, $context) {
        $timestamp = current_time('mysql');
        $level_str = strtoupper($level);
        
        // Convert message to string if it's an array or object
        if (!is_scalar($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE);
        }
        
        // Add context if provided
        $context_str = '';
        if (!empty($context)) {
            $context_str = ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        // Add memory usage for performance monitoring
        $memory_mb = round(memory_get_usage(true) / 1048576, 2);
        
        return sprintf(
            "[%s] [%s] [%sMB] %s%s\n",
            $timestamp,
            $level_str,
            $memory_mb,
            $message,
            $context_str
        );
    }
    
    /**
     * Write to log file
     */
    private function write_to_log($formatted_message) {
        // Skip if log file is not configured
        if (empty($this->log_file)) {
            return false;
        }

        // Rotate log based on file age
        if (function_exists('get_option') && function_exists('update_option')) {
            $created = (int) get_option('hic_log_created', 0);
            $rotation_days = apply_filters('hic_log_rotation_days', 7);
            $day_in_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
            $max_age = $rotation_days * $day_in_seconds;

            if (!file_exists($this->log_file)) {
                update_option('hic_log_created', time());
            } elseif ($created > 0 && (time() - $created) >= $max_age) {
                $this->rotate_log();
            } elseif ($created === 0) {
                update_option('hic_log_created', time());
            }
        }
        
        // Ensure directory exists
        $log_dir = dirname($this->log_file);
        if (!file_exists($log_dir)) {
            // Use wp_mkdir_p if available, otherwise mkdir
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($log_dir);
            } else {
                @mkdir($log_dir, 0755, true);
            }
        }
        
        // Check if directory is writable
        if (!is_writable($log_dir)) {
            // Cannot write to log directory
            return false;
        }
        
        // Write to file
        $result = file_put_contents($this->log_file, $formatted_message, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            // Failed to write to log file
            return false;
        }
        
        return true;
    }
    
    /**
     * Rotate log file if it exceeds the configured size
     */
    public function rotate_if_needed() {
        if (!file_exists($this->log_file)) {
            return false;
        }

        // Suppress potential warnings if the file is locked
        $file_size = @filesize($this->log_file);
        if ($file_size === false) {
            // Cannot get log file size
            return false;
        }

        if ($file_size > $this->max_size) {
            $this->rotate_log();
            return true;
        }

        return true;
    }
    
    /**
     * Rotate log file
     */
    private function rotate_log() {
        $timestamp = date('Y-m-d_H-i-s');
        $rotated_file = $this->log_file . '.' . $timestamp;
        
        // Move current log to rotated file
        if (rename($this->log_file, $rotated_file)) {
            if (function_exists('update_option')) {
                update_option('hic_log_created', time());
            }

            $this->log("Log rotated to: {$rotated_file}", HIC_LOG_LEVEL_INFO);

            // Compress rotated file if gzip is available
            if (function_exists('gzopen')) {
                $this->compress_log($rotated_file);
            }
        }
    }
    
    /**
     * Compress log file
     */
    private function compress_log($log_file) {
        $gz_file = $log_file . '.gz';
        
        $src = fopen($log_file, 'rb');
        $dest = gzopen($gz_file, 'wb9');
        
        if ($src && $dest) {
            while (!feof($src)) {
                gzwrite($dest, fread($src, 8192));
            }
            
            fclose($src);
            gzclose($dest);
            
            // Remove original file after compression
            unlink($log_file);
        }
    }
    
    /**
     * Clean up old log files
     */
    public function cleanup_old_logs() {
        $log_dir = dirname($this->log_file);
        $log_basename = basename($this->log_file);
        
        if (!is_dir($log_dir)) {
            return;
        }
        
        $cutoff_time = time() - ($this->retention_days * 86400);
        $files = glob($log_dir . '/' . $log_basename . '.*');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get log statistics
     */
    public function get_log_stats() {
        if (!file_exists($this->log_file)) {
            return [
                'exists' => false,
                'size' => 0,
                'lines' => 0,
                'last_modified' => null
            ];
        }
        
        $size = filesize($this->log_file);
        $lines = $this->count_log_lines();
        $last_modified = filemtime($this->log_file);
        
        return [
            'exists' => true,
            'size' => $size,
            'size_mb' => round($size / 1048576, 2),
            'lines' => $lines,
            'last_modified' => date('Y-m-d H:i:s', $last_modified),
            'rotated_files' => $this->get_rotated_files()
        ];
    }
    
    /**
     * Count lines in log file
     */
    private function count_log_lines() {
        $line_count = 0;
        $handle = fopen($this->log_file, 'r');
        
        if ($handle) {
            while (!feof($handle)) {
                fgets($handle);
                $line_count++;
            }
            fclose($handle);
        }
        
        return $line_count;
    }
    
    /**
     * Get list of rotated log files
     */
    private function get_rotated_files() {
        $log_dir = dirname($this->log_file);
        $log_basename = basename($this->log_file);
        $files = glob($log_dir . '/' . $log_basename . '.*');
        
        $rotated_files = [];
        foreach ($files as $file) {
            $rotated_files[] = [
                'file' => basename($file),
                'size' => filesize($file),
                'size_mb' => round(filesize($file) / 1048576, 2),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
        
        // Sort by modification time (newest first)
        usort($rotated_files, function($a, $b) {
            return strtotime($b['modified']) - strtotime($a['modified']);
        });
        
        return $rotated_files;
    }
    
    /**
     * Get recent log entries
     */
    public function get_recent_logs($lines = 100, $level_filter = null) {
        if (!file_exists($this->log_file)) {
            return [];
        }
        
        // Read last N lines
        $log_lines = $this->tail_file($this->log_file, $lines);
        
        // Parse and filter log entries
        $entries = [];
        foreach ($log_lines as $line) {
            $entry = $this->parse_log_line($line);
            if ($entry && ($level_filter === null || $entry['level'] === strtoupper($level_filter))) {
                $entries[] = $entry;
            }
        }
        
        return array_reverse($entries); // Most recent first
    }
    
    /**
     * Parse log line into structured data
     */
    private function parse_log_line($line) {
        // Match pattern: [timestamp] [level] [memory] message
        $pattern = '/^\[([^\]]+)\] \[([^\]]+)\] \[([^\]]+)\] (.+)$/';
        
        if (preg_match($pattern, trim($line), $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'memory' => $matches[3],
                'message' => $matches[4]
            ];
        }
        
        return null;
    }
    
    /**
     * Read last N lines from file
     */
    private function tail_file($file, $lines) {
        $buffer = 4096;
        $output = [];
        $chunk = [];
        
        $fp = fopen($file, 'rb');
        if (!$fp) {
            return [];
        }
        
        fseek($fp, -1, SEEK_END);
        
        if (fread($fp, 1) != "\n") {
            $lines -= 1;
        }
        
        $output = '';
        while (ftell($fp) > 0 && count($chunk) < $lines) {
            $seek = min(ftell($fp), $buffer);
            fseek($fp, -$seek, SEEK_CUR);
            $temp = fread($fp, $seek);
            fseek($fp, -$seek, SEEK_CUR);
            
            $output = $temp . $output;
            $chunk = explode("\n", $output);
        }
        
        fclose($fp);
        
        return array_slice($chunk, -$lines);
    }
    
    /**
     * Clear log file
     */
    public function clear_log() {
        if (file_exists($this->log_file)) {
            return file_put_contents($this->log_file, '');
        }
        return true;
    }
    
    /**
     * Archive current log
     */
    public function archive_log() {
        if (!file_exists($this->log_file)) {
            return false;
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $archive_file = $this->log_file . '.archive.' . $timestamp;
        
        if (copy($this->log_file, $archive_file)) {
            $this->clear_log();
            $this->log("Log archived to: {$archive_file}", HIC_LOG_LEVEL_INFO);
            return $archive_file;
        }
        
        return false;
    }
}

/**
 * Get or create global HIC_Log_Manager instance
 */
function hic_get_log_manager() {
    if (!isset($GLOBALS['hic_log_manager'])) {
        // Only instantiate if WordPress is loaded and functions are available
        if (function_exists('get_option') && function_exists('add_action')) {
            $GLOBALS['hic_log_manager'] = new HIC_Log_Manager();
        }
    }
    return isset($GLOBALS['hic_log_manager']) ? $GLOBALS['hic_log_manager'] : null;
}

/**
 * Enhanced hic_log function that uses the new log manager
 */
if (!function_exists('hic_log_enhanced')) {
    function hic_log_enhanced($message, $level = HIC_LOG_LEVEL_INFO, $context = []) {
        $log_manager = hic_get_log_manager();
        if ($log_manager) {
            return $log_manager->log($message, $level, $context);
        }
        return false;
    }
}