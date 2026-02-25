<?php
if (!defined('ABSPATH')) exit;

class MMBPL_Logger {
  public static function log($message) {
    error_log('[MMBPL] ' . $message);
  }
}