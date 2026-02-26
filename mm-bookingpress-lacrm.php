<?php
/*
Plugin Name: MM BookingPress to LACRM
Description: Sync BookingPress Pro bookings to Less Annoying CRM (create/update contact, create event, add note, delete on cancel via status watcher).
Version: 1.0.4
Author: Meridian Media
*/

if (!defined('ABSPATH')) exit;

define('MMBPL_PLUGIN_FILE', __FILE__);
define('MMBPL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MMBPL_OPT', 'mmbpl_settings');
define('MMBPL_LAST_OPT', 'mmbpl_last_processed_id');

require_once MMBPL_PLUGIN_DIR . 'includes/logger.php';
require_once MMBPL_PLUGIN_DIR . 'includes/map.php';
require_once MMBPL_PLUGIN_DIR . 'includes/lacrm.php';
require_once MMBPL_PLUGIN_DIR . 'includes/bookingpress.php';
require_once MMBPL_PLUGIN_DIR . 'includes/admin.php';

register_activation_hook(__FILE__, function () {
  MMBPL_Map::install();

  if (!get_option(MMBPL_OPT)) {
    add_option(MMBPL_OPT, [
      'lacrm_api_key'          => '',
      'event_title_template'   => '{service} booking',
      'add_note'               => 1,
      'delete_on_cancel'       => 1,
      'debug'                  => 0,
      'bp_tables'              => [],
      'watch_limit'            => 50,   // how many mapped bookings to recheck per run
    ]);
  }

  // schedule watcher
  if (!wp_next_scheduled('mmbpl_watch_status_cron')) {
    wp_schedule_event(time() + 120, 'mmbpl_five_minutes', 'mmbpl_watch_status_cron');
  }
});

register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled('mmbpl_watch_status_cron');
  if ($ts) wp_unschedule_event($ts, 'mmbpl_watch_status_cron');
});

add_filter('cron_schedules', function ($schedules) {
  if (!isset($schedules['mmbpl_five_minutes'])) {
    $schedules['mmbpl_five_minutes'] = [
      'interval' => 300,
      'display'  => 'Every 5 minutes (MMBPL)',
    ];
  }
  return $schedules;
});

MMBPL_Logger::log('Plugin loaded');

add_action('plugins_loaded', function () {

  // Created
  add_action('bookingpress_after_book_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_book_appointment fired. booking_id=' . (int) $booking_id);

    if ($booking_id) {
      MMBPL_Sync::handle_booking_created((int) $booking_id, $args);
      update_option(MMBPL_LAST_OPT, (int) $booking_id);
    }
  }, 10, 99);

  // Updated / rescheduled
  add_action('bookingpress_after_update_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_update_appointment fired. booking_id=' . (int) $booking_id);

    if ($booking_id) {
      MMBPL_Sync::handle_booking_updated((int) $booking_id, $args);
    }
  }, 10, 99);

  // Cancelled (may not fire on your install, but keep it anyway)
  add_action('bookingpress_after_cancel_appointment', function () {
    $args = func_get_args();

    MMBPL_Logger::log('HOOK bookingpress_after_cancel_appointment fired. raw_args=' . print_r($args, true));

    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    if (!$booking_id) {
      $candidates = [
        $_POST['bookingpress_appointment_booking_id'] ?? null,
        $_POST['appointment_booking_id'] ?? null,
        $_REQUEST['bookingpress_appointment_booking_id'] ?? null,
        $_REQUEST['appointment_booking_id'] ?? null,
        $_POST['booking_id'] ?? null,
        $_REQUEST['booking_id'] ?? null,
      ];
      foreach ($candidates as $c) {
        if (is_numeric($c) && (int) $c > 0) { $booking_id = (int) $c; break; }
      }
    }

    MMBPL_Logger::log('HOOK bookingpress_after_cancel_appointment parsed booking_id=' . (int) $booking_id);

    if ($booking_id) {
      MMBPL_Sync::handle_booking_cancelled((int) $booking_id, $args);
    } else {
      MMBPL_Logger::log('Cancel hook fired but booking_id could not be determined.');
    }
  }, 10, 99);
});

/**
 * Status watcher
 * Runs on cron and on admin requests to catch cancellations that do not fire hooks.
 */
add_action('mmbpl_watch_status_cron', function () {
  MMBPL_Watcher::run('cron');
});

add_action('admin_init', function () {
  // cheap safety net, only in wp-admin
  MMBPL_Watcher::run('admin_init');
});

class MMBPL_Watcher {

  public static function run($source = '') {
    $settings = get_option(MMBPL_OPT, []);
    $debug = !empty($settings['debug']);
    $delete_on_cancel = !empty($settings['delete_on_cancel']);

    if (!$delete_on_cancel) return;

    global $wpdb;

    $map_table = $wpdb->prefix . 'mmbpl_map';
    $book_table = self::detect_bookingpress_table();
    if (!$book_table) {
      if ($debug) MMBPL_Logger::log('Watcher: could not detect bookings table.');
      return;
    }

    $limit = isset($settings['watch_limit']) ? (int) $settings['watch_limit'] : 50;
    $limit = max(10, min(250, $limit));

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT booking_id, lacrm_event_id
         FROM {$map_table}
         ORDER BY id DESC
         LIMIT %d",
        $limit
      ),
      ARRAY_A
    );

    if (empty($rows)) return;

    foreach ($rows as $r) {
      $booking_id = (int) ($r['booking_id'] ?? 0);
      $event_id   = (string) ($r['lacrm_event_id'] ?? '');

      if (!$booking_id || $event_id === '') continue;

      $status = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT bookingpress_appointment_status
           FROM {$book_table}
           WHERE bookingpress_appointment_booking_id = %d",
          $booking_id
        )
      );

      if ($status === null) continue;

      if (MMBPL_Sync::is_cancelled_status($status)) {
        if ($debug) {
          MMBPL_Logger::log('Watcher: detected cancelled status. booking_id=' . $booking_id . ' status=' . $status . ' source=' . $source);
        }
        MMBPL_Sync::handle_booking_cancelled($booking_id, ['watcher_source' => $source]);
      }
    }
  }

  private static function detect_bookingpress_table() {
    global $wpdb;

    $settings = get_option(MMBPL_OPT, []);
    $tables = $settings['bp_tables'] ?? [];

    if (!empty($tables['booking_table'])) {
      return $tables['booking_table'];
    }

    // fallback: find table by name
    $like = '%' . $wpdb->esc_like('bookingpress_appointment_bookings') . '%';
    $t = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));

    return $t ? $t : '';
  }
}

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

    if (!self::acquire_lock($booking_id, 90)) {
      MMBPL_Logger::log('Create skipped due to lock. booking_id=' . $booking_id);
      return;
    }

    try {
      $settings = get_option(MMBPL_OPT, []);
      $debug = !empty($settings['debug']);

      if (empty($settings['lacrm_api_key'])) {
        MMBPL_Logger::log('No LACRM API key set. Skipping.');
        return;
      }

      $bp = MMBPL_BookingPress::get_booking_payload($booking_id);
      $bp = self::enrich_payload_from_row($booking_id, $bp);

      if (!$bp || empty($bp['customer_email'])) {
        MMBPL_Logger::log('No payload or missing customer_email for booking_id=' . $booking_id);
        return;
      }

      if (self::is_cancelled_status($bp['status'] ?? '')) {
        MMBPL_Logger::log('Booking is cancelled, skipping create. booking_id=' . $booking_id);
        return;
      }

      // idempotency: if already mapped, do not create again
      $existing_event_id = MMBPL_Map::get_event_id($booking_id);
      if (!empty($existing_event_id)) {
        if ($debug) MMBPL_Logger::log('Create skipped, mapping already exists. booking_id=' . $booking_id . ' event_id=' . $existing_event_id);
        return;
      }

      if ($debug) {
        MMBPL_Logger::log('CREATE booking_id=' . $booking_id . ' payload=' . print_r($bp, true));
      }

      // upsert contact
      $bp_for_contact = $bp;
      $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp_for_contact);
      if (!$contact_id) {
        MMBPL_Logger::log('Failed to upsert contact for booking_id=' . $booking_id);
        return;
      }

      $title = self::render_template($settings['event_title_template'] ?? '{service} booking', $bp);

      $start = self::make_datetime($bp['appointment_date'] ?? '', $bp['appointment_time'] ?? '');
      $end   = self::make_datetime($bp['appointment_date'] ?? '', $bp['appointment_time'] ?? '', 60);

      if (!$start || !$end) {
        MMBPL_Logger::log('Could not build start/end datetime for booking_id=' . $booking_id);
        return;
      }

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
        MMBPL_Map::set_event_id($booking_id, (string) $event_id);
      } else {
        MMBPL_Logger::log('CreateEvent failed for booking_id=' . $booking_id);
      }

      if (!empty($settings['add_note'])) {
        $ok = MMBPL_LACRM::create_note([
          'ContactId' => (string) $contact_id,
          'Note'      => self::build_summary($bp),
        ]);

        if (!$ok) {
          MMBPL_Logger::log('CreateNote failed for booking_id=' . $booking_id);
        }
      }

    } finally {
      self::release_lock($booking_id);
    }
  }

  public static function handle_booking_updated($booking_id, $hook_args = []) {
    $booking_id = (int) $booking_id;

    if (!self::acquire_lock($booking_id, 90)) {
      MMBPL_Logger::log('Update skipped due to lock. booking_id=' . $booking_id);
      return;
    }

    try {
      $settings = get_option(MMBPL_OPT, []);
      $debug = !empty($settings['debug']);
      if (empty($settings['lacrm_api_key'])) return;

      $bp = MMBPL_BookingPress::get_booking_payload($booking_id);
      $bp = self::enrich_payload_from_row($booking_id, $bp);

      if (!$bp || empty($bp['customer_email'])) return;

      if (self::is_cancelled_status($bp['status'] ?? '')) {
        self::handle_booking_cancelled($booking_id, $hook_args);
        return;
      }

      if ($debug) {
        MMBPL_Logger::log('UPDATE booking_id=' . $booking_id . ' payload=' . print_r($bp, true));
      }

      $bp_for_contact = $bp;
      $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp_for_contact);
      if (!$contact_id) return;

      $existing_event_id = MMBPL_Map::get_event_id($booking_id);

      // delete and recreate
      if (!empty($existing_event_id)) {
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
        MMBPL_Map::set_event_id($booking_id, (string) $new_event_id);
      }

    } finally {
      self::release_lock($booking_id);
    }
  }

  public static function handle_booking_cancelled($booking_id, $hook_args = []) {
    $booking_id = (int) $booking_id;

    if (!self::acquire_lock($booking_id, 60)) {
      MMBPL_Logger::log('Cancel skipped due to lock. booking_id=' . $booking_id);
      return;
    }

    try {
      $settings = get_option(MMBPL_OPT, []);
      if (empty($settings['lacrm_api_key'])) return;
      if (empty($settings['delete_on_cancel'])) return;

      $event_id = MMBPL_Map::get_event_id($booking_id);
      MMBPL_Logger::log('Cancel: mapping lookup booking_id=' . $booking_id . ' event_id=' . (!empty($event_id) ? $event_id : 'none'));

      if (empty($event_id)) {
        return;
      }

      $ok = MMBPL_LACRM::delete_event((string) $event_id);

      if ($ok) {
        MMBPL_Map::delete_mapping($booking_id);
        MMBPL_Logger::log('Cancel: deleted event and removed mapping. booking_id=' . $booking_id);
      } else {
        MMBPL_Logger::log('Cancel: DeleteEvent failed. booking_id=' . $booking_id . ' event_id=' . $event_id);
      }

    } finally {
      self::release_lock($booking_id);
    }
  }

  public static function is_cancelled_status($status) {
    $raw = trim((string) $status);
    if ($raw === '') return false;

    // You saw cancelled as "3" in phpMyAdmin
    if (ctype_digit($raw)) {
      $code = (int) $raw;
      return in_array($code, [3, 4], true);
    }

    $s = strtolower($raw);
    return in_array($s, [
      'cancelled','canceled','cancel','cancelled_by_admin','cancelled_by_customer','rejected'
    ], true);
  }

  private static function enrich_payload_from_row($booking_id, $bp) {
    if (!is_array($bp)) $bp = [];

    // If these keys already exist, keep them
    $needs_status = !array_key_exists('status', $bp) || $bp['status'] === '' || $bp['status'] === null;
    $needs_note   = !array_key_exists('internal_note', $bp) || $bp['internal_note'] === '' || $bp['internal_note'] === null;

    if (!$needs_status && !$needs_note) return $bp;

    global $wpdb;

    $settings = get_option(MMBPL_OPT, []);
    $tables = $settings['bp_tables'] ?? [];
    $bt = !empty($tables['booking_table']) ? $tables['booking_table'] : '';

    if (!$bt) {
      $like = '%' . $wpdb->esc_like('bookingpress_appointment_bookings') . '%';
      $bt = (string) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));
    }

    if (!$bt) return $bp;

    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT bookingpress_appointment_status, bookingpress_appointment_internal_note
         FROM {$bt}
         WHERE bookingpress_appointment_booking_id = %d",
        (int) $booking_id
      ),
      ARRAY_A
    );

    if (!$row) return $bp;

    if ($needs_status && isset($row['bookingpress_appointment_status'])) {
      $bp['status'] = (string) $row['bookingpress_appointment_status'];
    }

    if ($needs_note && isset($row['bookingpress_appointment_internal_note'])) {
      $bp['internal_note'] = (string) $row['bookingpress_appointment_internal_note'];
    }

    return $bp;
  }

  private static function make_datetime($date, $time, $add_minutes = 0) {
    $date = trim((string) $date);
    $time = trim((string) $time);

    if ($date === '') return '';
    if ($time === '') $time = '00:00:00';

    $dt = strtotime($date . ' ' . $time);
    if (!$dt) return '';

    if ($add_minutes > 0) $dt += ((int) $add_minutes * 60);

    return gmdate('Y-m-d\TH:i:s\Z', $dt);
  }

  private static function build_summary($bp) {
    $lines = [];

    if (!empty($bp['service_name'])) $lines[] = 'Service: ' . $bp['service_name'];
    if (!empty($bp['appointment_date'])) $lines[] = 'Date: ' . $bp['appointment_date'];
    if (!empty($bp['appointment_time'])) $lines[] = 'Time: ' . $bp['appointment_time'];

    $name = trim(($bp['customer_first_name'] ?? '') . ' ' . ($bp['customer_last_name'] ?? ''));
    if ($name !== '') $lines[] = 'Customer: ' . $name;

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