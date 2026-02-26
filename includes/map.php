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

    // booking_hash lets us detect changes and resync events
    $sql = "CREATE TABLE {$table} (
      booking_id BIGINT(20) UNSIGNED NOT NULL,
      event_id VARCHAR(64) NOT NULL,
      booking_hash CHAR(64) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (booking_id),
      KEY event_id (event_id)
    ) {$charset};";

    dbDelta($sql);
  }

  public static function set_mapping($booking_id, $event_id, $booking_hash = '') {
    global $wpdb;
    $table = self::table_name();

    $wpdb->replace(
      $table,
      [
        'booking_id'    => (int) $booking_id,
        'event_id'      => (string) $event_id,
        'booking_hash'  => (string) $booking_hash,
        'updated_at'    => current_time('mysql'),
      ],
      ['%d','%s','%s','%s']
    );
  }

  public static function get_mapping($booking_id) {
    global $wpdb;
    $table = self::table_name();

    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT booking_id, event_id, booking_hash FROM {$table} WHERE booking_id=%d", (int) $booking_id),
      ARRAY_A
    );

    return $row ?: null;
  }

  public static function get_event_id($booking_id) {
    $row = self::get_mapping($booking_id);
    return $row ? (string) $row['event_id'] : '';
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