<?php
if (!defined('ABSPATH')) exit;

class MMBPL_Logger {

  private static function log_path() {
    $upload = wp_upload_dir();
    return trailingslashit($upload['basedir']) . 'mmbpl.log';
  }

  public static function log($message) {
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] [MMBPL] ' . $message . "\n";
    $path = self::log_path();

    // Write to our own log file
    @file_put_contents($path, $line, FILE_APPEND);

    // Also try PHP error log as a backup
    @error_log($line);
  }
}