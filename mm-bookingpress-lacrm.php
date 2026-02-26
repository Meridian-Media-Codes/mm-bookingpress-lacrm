<?php
/*
Plugin Name: MM BookingPress to LACRM
Description: Sync BookingPress Pro bookings to Less Annoying CRM (create/update contact, create event, add note, delete on cancel, resync on update).
Version: 1.0.4
Author: Meridian Media
*/

if (!defined('ABSPATH')) exit;

define('MMBPL_PLUGIN_FILE', __FILE__);
define('MMBPL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MMBPL_OPT', 'mmbpl_settings');
define('MMBPL_LAST_OPT', 'mmbpl_last_processed_id');
define('MMBPL_CRON_HOOK', 'mmbpl_poll_bookingpress');

require_once MMBPL_PLUGIN_DIR . 'includes/logger.php';
require_once MMBPL_PLUGIN_DIR . 'includes/map.php';
require_once MMBPL_PLUGIN_DIR . 'includes/lacrm.php';
require_once MMBPL_PLUGIN_DIR . 'includes/bookingpress.php';
require_once MMBPL_PLUGIN_DIR . 'includes/admin.php';

register_activation_hook(__FILE__, function () {
  MMBPL_Map::install();

  if (!get_option(MMBPL_OPT)) {
    add_option(MMBPL_OPT, [
      'lacrm_api_key' => '',
      'event_title_template' => '{service} booking',
      'add_note' => 1,
      'delete_on_cancel' => 1,
      'debug' => 0,
      'bp_tables' => [],
      'poll_interval_minutes' => 2,
      'recheck_mapped_limit' => 200,
    ]);
  }

  if (!wp_next_scheduled(MMBPL_CRON_HOOK)) {
    wp_schedule_event(time() + 60, 'mmbpl_every_two_minutes', MMBPL_CRON_HOOK);
  }
});

register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled(MMBPL_CRON_HOOK);
  if ($ts) wp_unschedule_event($ts, MMBPL_CRON_HOOK);
});

add_filter('cron_schedules', function ($schedules) {
  if (!isset($schedules['mmbpl_every_two_minutes'])) {
    $schedules['mmbpl_every_two_minutes'] = [
      'interval' => 120,
      'display'  => 'Every 2 minutes (MMBPL)'
    ];
  }
  return $schedules;
});

add_action('plugins_loaded', function () {

  // Created
  add_action('bookingpress_after_book_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    $settings = get_option(MMBPL_OPT, []);
    if (!empty($settings['debug'])) {
      MMBPL_Logger::log('HOOK bookingpress_after_book_appointment fired. booking_id=' . (int) $booking_id);
    }

    if ($booking_id) {
      MMBPL_Sync::handle_booking_created((int) $booking_id, $args);
      update_option(MMBPL_LAST_OPT, (int) $booking_id);
    }
  }, 10, 10);

  // Updated / rescheduled
  add_action('bookingpress_after_update_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    $settings = get_option(MMBPL_OPT, []);
    if (!empty($settings['debug'])) {
      MMBPL_Logger::log('HOOK bookingpress_after_update_appointment fired. booking_id=' . (int) $booking_id);
    }

    if ($booking_id) {
      MMBPL_Sync::handle_booking_updated((int) $booking_id, $args);
    }
  }, 10, 10);

  // Cancelled (may not fire on your install, but keep it anyway)
  add_action('bookingpress_after_cancel_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    $settings = get_option(MMBPL_OPT, []);
    if (!empty($settings['debug'])) {
      MMBPL_Logger::log('HOOK bookingpress_after_cancel_appointment fired. booking_id=' . (int) $booking_id);
    }

    if ($booking_id) {
      MMBPL_Sync::handle_booking_cancelled((int) $booking_id, $args);
    }
  }, 10, 99);
});

/**
 * Poller: detects new bookings (by id), cancellations (by status), and changes (by hash).
 * This solves installs where BookingPress does not fire cancel hooks.
 */
add_action(MMBPL_CRON_HOOK, function () {

  $settings = get_option(MMBPL_OPT, []);
  $debug = !empty($settings['debug']);

  global $wpdb;
  $table = $wpdb->get_var("SHOW TABLES LIKE '%bookingpress_appointment_bookings%'");
  if (!$table) {
    if ($debug) MMBPL_Logger::log('Poll: bookingpress_appointment_bookings table not found.');
    return;
  }

  $pk = 'bookingpress_appointment_booking_id';

  // A) New bookings by increasing id
  $last_processed = (int) get_option(MMBPL_LAST_OPT, 0);
  $latest_id = (int) $wpdb->get_var("SELECT MAX({$pk}) FROM {$table}");

  if ($latest_id && $latest_id > $last_processed) {
    $new_ids = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT {$pk} FROM {$table} WHERE {$pk} > %d ORDER BY {$pk} ASC LIMIT 25",
        $last_processed
      )
    );

    foreach ((array) $new_ids as $bid) {
      $bid = (int) $bid;
      if ($debug) MMBPL_Logger::log('Poll: new booking detected booking_id=' . $bid);
      MMBPL_Sync::handle_booking_created($bid);
      update_option(MMBPL_LAST_OPT, $bid);
    }
  }

  // B) Recheck mapped bookings for cancellations and changes
  $limit = isset($settings['recheck_mapped_limit']) ? (int) $settings['recheck_mapped_limit'] : 200;
  $limit = max(25, min(1000, $limit));

  $mapped_booking_ids = MMBPL_Map::list_booking_ids_with_events($limit);
  if (empty($mapped_booking_ids)) return;

  foreach ($mapped_booking_ids as $bid) {
    $bid = (int) $bid;

    $bp = MMBPL_BookingPress::get_booking_payload($bid);
    if (!$bp) continue;

    // Cancel detection by status
    if (MMBPL_Sync::is_cancelled_status($bp['status'] ?? '')) {
      if ($debug) MMBPL_Logger::log('Poll: booking cancelled in DB booking_id=' . $bid . ' status=' . (string) ($bp['status'] ?? ''));
      MMBPL_Sync::handle_booking_cancelled($bid);
      continue;
    }

    // Change detection by hash
    $map = MMBPL_Map::get_mapping($bid);
    if (!$map) continue;

    $current_hash = MMBPL_Sync::booking_hash($bp);
    $stored_hash = (string) ($map['booking_hash'] ?? '');

    if ($stored_hash !== '' && $current_hash !== $stored_hash) {
      if ($debug) MMBPL_Logger::log('Poll: booking changed booking_id=' . $bid);
      MMBPL_Sync::handle_booking_updated($bid);
    }
  }
});

class MMBPL_Sync {

  private static function acquire_lock($booking_id, $seconds = 60) {
    $key = 'mmbpl_lock_' . (int) $booking_id;
    if (get_transient($key)) return false;
    set_transient($key, 1, (int) $seconds);
    return true;
  }

  private static function release_lock($booking_id) {
    delete_transient('mmbpl_lock_' . (int) $booking_id);
  }

  public static function handle_booking_created($booking_id, $hook_args = []) {
    $booking_id = (int) $booking_id;

    if (!self::acquire_lock($booking_id, 90)) return;

    try {
      $settings = get_option(MMBPL_OPT, []);
      $debug = !empty($settings['debug']);

      if (empty($settings['lacrm_api_key'])) return;

      $bp = MMBPL_BookingPress::get_booking_payload($booking_id);
      if (!$bp || empty($bp['customer_email'])) return;

      if (self::is_cancelled_status($bp['status'] ?? '')) return;

      $existing = MMBPL_Map::get_mapping($booking_id);
      $current_hash = self::booking_hash($bp);

      // Idempotency: already synced and unchanged
      if ($existing && !empty($existing['lacrm_event_id']) && !empty($existing['booking_hash'])) {
        if (hash_equals((string) $existing['booking_hash'], (string) $current_hash)) {
          if ($debug) MMBPL_Logger::log('Create: already synced booking_id=' . $booking_id);
          return;
        }
      }

      // If mapped but changed, update flow
      if ($existing && !empty($existing['lacrm_event_id'])) {
        self::handle_booking_updated($booking_id, $hook_args);
        return;
      }

      if ($debug) {
        MMBPL_Logger::log('CREATE booking_id=' . $booking_id . ' payload=' . print_r($bp, true));
      }

      $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp);
      if (!$contact_id) return;

      $title = self::render_template($settings['event_title_template'] ?? '{service} booking', $bp);

      $start = self::make_datetime($bp['appointment_date'] ?? '', $bp['appointment_time'] ?? '');
      $end   = self::make_datetime($bp['appointment_date'] ?? '', $bp['appointment_time'] ?? '', 60);
      if (!$start || !$end) return;

      $event_id = MMBPL_LACRM::create_event([
        'ContactId'   => (string) $contact_id,
        'Name'        => $title,
        'StartDate'   => $start,
        'EndDate'     => $end,
        'Description' => self::build_summary($bp),
        'Location'    => '',
        'IsAllDay'    => false,
      ]);

      if ($event_id) {
        MMBPL_Map::set_mapping($booking_id, (string) $event_id, $current_hash);
      }

      if (!empty($settings['add_note'])) {
        MMBPL_LACRM::create_note([
          'ContactId' => (string) $contact_id,
          'Note'      => self::build_summary($bp),
        ]);
      }

    } finally {
      self::release_lock($booking_id);
    }
  }

  public static function handle_booking_updated($booking_id, $hook_args = []) {
    $booking_id = (int) $booking_id;

    if (!self::acquire_lock($booking_id, 90)) return;

    try {
      $settings = get_option(MMBPL_OPT, []);
      $debug = !empty($settings['debug']);

      if (empty($settings['lacrm_api_key'])) return;

      $bp = MMBPL_BookingPress::get_booking_payload($booking_id);
      if (!$bp || empty($bp['customer_email'])) return;

      if (self::is_cancelled_status($bp['status'] ?? '')) {
        self::handle_booking_cancelled($booking_id, $hook_args);
        return;
      }

      if ($debug) {
        MMBPL_Logger::log('UPDATE booking_id=' . $booking_id . ' payload=' . print_r($bp, true));
      }

      $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp);
      if (!$contact_id) return;

      $existing_event_id = MMBPL_Map::get_event_id($booking_id);
      if ($existing_event_id) {
        MMBPL_LACRM::delete_event((string) $existing_event_id);
      }

      $title = self::render_template($settings['event_title_template'] ?? '{service} booking', $bp);

      $start = self::make_datetime($bp['appointment_date'] ?? '', $bp['appointment_time'] ?? '');
      $end   = self::make_datetime($bp['appointment_date'] ?? '', $bp['appointment_time'] ?? '', 60);
      if (!$start || !$end) return;

      $new_event_id = MMBPL_LACRM::create_event([
        'ContactId'   => (string) $contact_id,
        'Name'        => $title,
        'StartDate'   => $start,
        'EndDate'     => $end,
        'Description' => self::build_summary($bp),
        'Location'    => '',
        'IsAllDay'    => false,
      ]);

      if ($new_event_id) {
        $hash = self::booking_hash($bp);
        MMBPL_Map::set_mapping($booking_id, (string) $new_event_id, $hash);
      }

    } finally {
      self::release_lock($booking_id);
    }
  }

  public static function handle_booking_cancelled($booking_id, $hook_args = []) {
    $booking_id = (int) $booking_id;

    if (!self::acquire_lock($booking_id, 60)) return;

    try {
      $settings = get_option(MMBPL_OPT, []);
      $debug = !empty($settings['debug']);

      if (empty($settings['lacrm_api_key'])) return;
      if (empty($settings['delete_on_cancel'])) return;

      $event_id = MMBPL_Map::get_event_id($booking_id);

      if ($debug) {
        MMBPL_Logger::log('Cancel: booking_id=' . $booking_id . ' mapped_event_id=' . ($event_id ?: 'none'));
      }

      if (!$event_id) return;

      $ok = MMBPL_LACRM::delete_event((string) $event_id);
      if ($ok) {
        MMBPL_Map::delete_mapping($booking_id);
        if ($debug) MMBPL_Logger::log('Cancel: deleted and unmapped booking_id=' . $booking_id);
      }

    } finally {
      self::release_lock($booking_id);
    }
  }

  public static function is_cancelled_status($status) {
    $raw = trim((string) $status);
    if ($raw === '') return false;

    // Your DB shows bookingpress_appointment_status and cancelled looked like 3
    if (ctype_digit($raw)) {
      $code = (int) $raw;
      return in_array($code, [3, 4], true);
    }

    $s = strtolower($raw);
    return in_array($s, ['cancelled','canceled','cancel','rejected'], true);
  }

  public static function booking_hash($bp) {
    $parts = [
      (string) ($bp['customer_email'] ?? ''),
      (string) ($bp['service_name'] ?? ''),
      (string) ($bp['appointment_date'] ?? ''),
      (string) ($bp['appointment_time'] ?? ''),
      (string) ($bp['status'] ?? ''),
      (string) ($bp['customer_note'] ?? ''),
      (string) ($bp['internal_note'] ?? ''),
    ];
    return hash('sha256', implode('|', $parts));
  }

  private static function make_datetime($date, $time, $add_minutes = 0) {
    $date = trim((string) $date);
    $time = trim((string) $time);

    if (!$date) return '';
    if (!$time) $time = '00:00:00';

    $dt = strtotime($date . ' ' . $time);
    if (!$dt) return '';

    if ($add_minutes > 0) $dt += ($add_minutes * 60);

    return gmdate('Y-m-d\TH:i:s\Z', $dt);
  }

  private static function build_summary($bp) {
    $lines = [];

    if (!empty($bp['service_name'])) $lines[] = 'Service: ' . $bp['service_name'];
    if (!empty($bp['appointment_date'])) $lines[] = 'Date: ' . $bp['appointment_date'];
    if (!empty($bp['appointment_time'])) $lines[] = 'Time: ' . $bp['appointment_time'];

    $name = trim(($bp['customer_first_name'] ?? '') . ' ' . ($bp['customer_last_name'] ?? ''));
    if ($name) $lines[] = 'Customer: ' . $name;

    if (!empty($bp['customer_email'])) $lines[] = 'Email: ' . $bp['customer_email'];
    if (!empty($bp['customer_phone'])) $lines[] = 'Phone: ' . $bp['customer_phone'];

    if (!empty($bp['customer_note'])) {
      $lines[] = '';
      $lines[] = 'Customer note:';
      $lines[] = (string) $bp['customer_note'];
    }

    if (!empty($bp['internal_note'])) {
      $lines[] = '';
      $lines[] = 'BookingPress internal note:';
      $lines[] = (string) $bp['internal_note'];
    }

    if (isset($bp['status']) && (string) $bp['status'] !== '') {
      $lines[] = '';
      $lines[] = 'Status: ' . (string) $bp['status'];
    }

    $lines[] = '';
    $lines[] = 'Source: BookingPress Pro';

    return implode("\n", $lines);
  }

  private static function render_template($tpl, $bp) {
    $replacements = [
      '{service}' => $bp['service_name'] ?? '',
      '{date}'    => $bp['appointment_date'] ?? '',
      '{time}'    => $bp['appointment_time'] ?? '',
      '{email}'   => $bp['customer_email'] ?? '',
      '{name}'    => trim(($bp['customer_first_name'] ?? '') . ' ' . ($bp['customer_last_name'] ?? '')),
    ];

    return trim(strtr((string) $tpl, $replacements));
  }
}