<?php
if (!defined('ABSPATH')) exit;

class MMBPL_Map {

  private static function table_name() {
    global $wpdb;
    return $wpdb->prefix . 'mmbpl_map';
  }

  public static function install() {
    global $wpdb;

    $table = self::table_name();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Create the modern schema (dbDelta will not always rename old columns, so we also migrate below)
    $sql = "CREATE TABLE {$table} (
      booking_id BIGINT(20) UNSIGNED NOT NULL,
      event_id VARCHAR(64) NOT NULL,
      booking_hash CHAR(64) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (booking_id),
      KEY event_id (event_id)
    ) {$charset};";

    dbDelta($sql);

    // Migrate legacy schema if present (id + lacrm_event_id)
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    if (!is_array($cols) || empty($cols)) return;

    $has_event_id = in_array('event_id', $cols, true);
    $has_lacrm_event_id = in_array('lacrm_event_id', $cols, true);
    $has_booking_hash = in_array('booking_hash', $cols, true);

    if (!$has_event_id && $has_lacrm_event_id) {
      $wpdb->query("ALTER TABLE {$table} CHANGE lacrm_event_id event_id VARCHAR(64) NOT NULL");
    }

    if (!$has_booking_hash) {
      $wpdb->query("ALTER TABLE {$table} ADD booking_hash CHAR(64) NOT NULL DEFAULT ''");
    }

    // Ensure booking_id is unique so upserts behave
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
    $has_unique_booking = false;
    if (is_array($indexes)) {
      foreach ($indexes as $idx) {
        if (!empty($idx['Column_name']) && $idx['Column_name'] === 'booking_id' && (int) ($idx['Non_unique'] ?? 1) === 0) {
          $has_unique_booking = true;
          break;
        }
      }
    }

    if (!$has_unique_booking) {
      // If booking_id is already PRIMARY KEY this will fail harmlessly
      $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY booking_id_unique (booking_id)");
    }
  }

  public static function set_mapping($booking_id, $event_id, $booking_hash = '') {
    global $wpdb;
    $table = self::table_name();

    // Use INSERT .. ON DUPLICATE KEY UPDATE so it works with UNIQUE booking_id
    $wpdb->query($wpdb->prepare(
      "INSERT INTO {$table} (booking_id, event_id, booking_hash, updated_at)
       VALUES (%d, %s, %s, %s)
       ON DUPLICATE KEY UPDATE
         event_id = VALUES(event_id),
         booking_hash = VALUES(booking_hash),
         updated_at = VALUES(updated_at)",
      (int) $booking_id,
      (string) $event_id,
      (string) $booking_hash,
      current_time('mysql')
    ));
  }

  public static function get_mapping($booking_id) {
    global $wpdb;
    $table = self::table_name();

    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT booking_id, event_id, booking_hash FROM {$table} WHERE booking_id=%d LIMIT 1", (int) $booking_id),
      ARRAY_A
    );

    return $row ?: null;
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