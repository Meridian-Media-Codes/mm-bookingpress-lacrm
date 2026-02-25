<?php
if (!defined('ABSPATH')) exit;

class MMBPL_Map {

  public static function table() {
    global $wpdb;
    return $wpdb->prefix . 'mmbpl_map';
  }

  public static function install() {
    global $wpdb;

    $table = self::table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      booking_id BIGINT UNSIGNED NOT NULL,
      lacrm_event_id VARCHAR(64) NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY booking_id (booking_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
  }

  public static function set_event_id($booking_id, $event_id) {
    global $wpdb;

    $wpdb->replace(self::table(), [
      'booking_id' => (int) $booking_id,
      'lacrm_event_id' => (string) $event_id,
      'updated_at' => current_time('mysql'),
    ], [
      '%d','%s','%s'
    ]);
  }

  public static function get_event_id($booking_id) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare(
      "SELECT lacrm_event_id FROM " . self::table() . " WHERE booking_id=%d",
      (int) $booking_id
    ));
  }

  public static function delete_mapping($booking_id) {
    global $wpdb;
    $wpdb->delete(self::table(), ['booking_id' => (int) $booking_id], ['%d']);
  }
}