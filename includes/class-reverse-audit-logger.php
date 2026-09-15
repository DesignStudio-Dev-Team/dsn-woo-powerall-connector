<?php
namespace DSNWooPowerall;

class Reverse_Audit_Logger {
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
        $this->log_file = $upload_dir['basedir'] . '/dsn-woo-powerall-reverse-audit.log';
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @return bool Whether the message was logged successfully
     */
    public function log($message) {
        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf(
            "[%s] %s\n",
            $timestamp,
            $message
        );

        return file_put_contents($this->log_file, $log_entry, FILE_APPEND);
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
