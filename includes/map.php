<?php
if (!defined('ABSPATH')) exit;

class MMBPL_Map {

  private static $cols_cache = null;

  private static function table_name() {
    global $wpdb;
    return $wpdb->prefix . 'mmbpl_map';
  }

  private static function cols() {
    if (is_array(self::$cols_cache)) return self::$cols_cache;

    global $wpdb;
    $table = self::table_name();
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    self::$cols_cache = is_array($cols) ? $cols : [];
    return self::$cols_cache;
  }

  private static function has_col($col) {
    return in_array($col, self::cols(), true);
  }

  public static function install() {
    global $wpdb;

    $table = self::table_name();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Create if missing (legacy installs may already exist with id PK)
    $sql = "CREATE TABLE {$table} (
      booking_id BIGINT(20) UNSIGNED NOT NULL,
      event_id VARCHAR(64) NOT NULL,
      booking_hash CHAR(64) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (booking_id),
      KEY event_id (event_id)
    ) {$charset};";

    dbDelta($sql);

    // Refresh cache after dbDelta
    self::$cols_cache = null;

    // Legacy migration: lacrm_event_id -> event_id
    if (!self::has_col('event_id') && self::has_col('lacrm_event_id')) {
      $wpdb->query("ALTER TABLE {$table} CHANGE lacrm_event_id event_id VARCHAR(64) NOT NULL");
      self::$cols_cache = null;
    }

    // Ensure booking_hash exists
    if (!self::has_col('booking_hash')) {
      $wpdb->query("ALTER TABLE {$table} ADD booking_hash CHAR(64) NOT NULL DEFAULT '' AFTER event_id");
      self::$cols_cache = null;
    }

    // Ensure booking_id unique (even if table uses id PK)
    $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY booking_id_unique (booking_id)");
  }

  public static function set_mapping($booking_id, $event_id, $booking_hash = '') {
    global $wpdb;
    $table = self::table_name();

    $booking_id = (int) $booking_id;
    $event_id = (string) $event_id;
    $booking_hash = (string) $booking_hash;

    $has_hash = self::has_col('booking_hash');

    if ($has_hash) {
      $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (booking_id, event_id, booking_hash, updated_at)
         VALUES (%d, %s, %s, %s)
         ON DUPLICATE KEY UPDATE
           event_id = VALUES(event_id),
           booking_hash = VALUES(booking_hash),
           updated_at = VALUES(updated_at)",
        $booking_id,
        $event_id,
        $booking_hash,
        current_time('mysql')
      ));
      return;
    }

    // Fallback for legacy schema with no booking_hash
    $wpdb->query($wpdb->prepare(
      "INSERT INTO {$table} (booking_id, event_id, updated_at)
       VALUES (%d, %s, %s)
       ON DUPLICATE KEY UPDATE
         event_id = VALUES(event_id),
         updated_at = VALUES(updated_at)",
      $booking_id,
      $event_id,
      current_time('mysql')
    ));
  }

  public static function get_mapping($booking_id) {
    global $wpdb;
    $table = self::table_name();
    $booking_id = (int) $booking_id;

    $has_hash = self::has_col('booking_hash');

    if ($has_hash) {
      $row = $wpdb->get_row(
        $wpdb->prepare("SELECT booking_id, event_id, booking_hash FROM {$table} WHERE booking_id=%d LIMIT 1", $booking_id),
        ARRAY_A
      );
      return $row ?: null;
    }

    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT booking_id, event_id FROM {$table} WHERE booking_id=%d LIMIT 1", $booking_id),
      ARRAY_A
    );

    if (!$row) return null;
    $row['booking_hash'] = '';
    return $row;
  }

  public static function get_event_id($booking_id) {
    $row = self::get_mapping($booking_id);
    return $row ? (string) ($row['event_id'] ?? '') : '';
  }

  public static function delete_mapping($booking_id) {
    global $wpdb;
    $table = self::table_name();
    $wpdb->delete($table, ['booking_id' => (int) $booking_id], ['%d']);
  }

  public static function list_booking_ids_with_events($limit = 500) {
    global $wpdb;
    $table = self::table_name();

    $limit = max(1, (int) $limit);
    return $wpdb->get_col("SELECT booking_id FROM {$table} ORDER BY updated_at DESC LIMIT {$limit}");
  }
}