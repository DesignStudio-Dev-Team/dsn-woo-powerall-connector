<?php
namespace DSNWooPowerall;

class Logger {
    /**
     * Log file path
     *
     * @var string
     */
    private $log_file;

    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/dsn-woo-powerall.log';
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level Log level (info, error, warning)
     * @return bool Whether the message was logged successfully
     */
    public function log($message, $level = 'info') {
        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            $timestamp,
            strtoupper($level),
            $message
        );

        return file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }

    /**
     * Log an info message
     *
     * @param string $message Message to log
     * @return bool Whether the message was logged successfully
     */
    public function info($message) {
        return $this->log($message, 'info');
    }

    /**
     * Log an error message
     *
     * @param string $message Message to log
     * @return bool Whether the message was logged successfully
     */
    public function error($message) {
        return $this->log($message, 'error');
    }

    /**
     * Log a warning message
     *
     * @param string $message Message to log
     * @return bool Whether the message was logged successfully
     */
    public function warning($message) {
        return $this->log($message, 'warning');
    }

    /**
     * Get the log file path
     *
     * @return string Log file path
     */
    public function get_log_file_path() {
        return $this->log_file;
    }

    /**
     * Clear the log file
     *
     * @return bool Whether the log file was cleared successfully
     */
    public function clear_log() {
        return file_put_contents($this->log_file, '');
    }
} 