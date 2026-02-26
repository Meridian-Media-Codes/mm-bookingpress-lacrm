<?php
/*
Plugin Name: MM BookingPress to LACRM
Description: Sync BookingPress Pro bookings to Less Annoying CRM (create/update contact, create event, add note, delete on cancel via status polling).
Version: 1.0.4
Author: Meridian Media
*/

if (!defined('ABSPATH')) exit;

define('MMBPL_PLUGIN_FILE', __FILE__);
define('MMBPL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MMBPL_OPT', 'mmbpl_settings');

require_once MMBPL_PLUGIN_DIR . 'includes/logger.php';
require_once MMBPL_PLUGIN_DIR . 'includes/map.php';
require_once MMBPL_PLUGIN_DIR . 'includes/lacrm.php';
require_once MMBPL_PLUGIN_DIR . 'includes/bookingpress.php';
require_once MMBPL_PLUGIN_DIR . 'includes/admin.php';

/**
 * Poller settings
 */
define('MMBPL_CRON_HOOK', 'mmbpl_poll_bookingpress_status');
define('MMBPL_LOCK_PREFIX', 'mmbpl_lock_');

/**
 * Your install shows cancelled as status=3 in bookingpress_appointment_status.
 * If you later learn it can also be 4, add it here.
 */
function mmbpl_is_cancelled_status($raw_status) {
  $raw = trim((string) $raw_status);
  if ($raw === '') return false;

  if (ctype_digit($raw)) {
    $code = (int) $raw;
    return in_array($code, [3], true);
  }

  $s = strtolower($raw);
  return in_array($s, ['cancelled','canceled','cancel','rejected'], true);
}

function mmbpl_acquire_lock($key, $seconds) {
  $tkey = MMBPL_LOCK_PREFIX . $key;
  if (get_transient($tkey)) return false;
  set_transient($tkey, 1, (int) $seconds);
  return true;
}

function mmbpl_release_lock($key) {
  delete_transient(MMBPL_LOCK_PREFIX . $key);
}

register_activation_hook(__FILE__, function () {
  // Ensure mapping table exists
  if (class_exists('MMBPL_Map')) {
    MMBPL_Map::install();
  }

  // Default settings
  if (!get_option(MMBPL_OPT)) {
    add_option(MMBPL_OPT, [
      'lacrm_api_key' => '',
      'event_title_template' => '{service} booking',
      'add_note' => 1,
      'delete_on_cancel' => 1,
      'debug' => 0,
      'bp_tables' => [],
      // How many mapped bookings to recheck each poll run
      'recheck_mapped_limit' => 200,
    ]);
  }

  // Add custom 1-minute schedule (safe for low traffic sites)
  add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['mmbpl_1min'])) {
      $schedules['mmbpl_1min'] = [
        'interval' => 60,
        'display'  => 'Every minute (MMBPL)',
      ];
    }
    return $schedules;
  });

  if (!wp_next_scheduled(MMBPL_CRON_HOOK)) {
    wp_schedule_event(time() + 60, 'mmbpl_1min', MMBPL_CRON_HOOK);
  }
});

register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled(MMBPL_CRON_HOOK);
  if ($ts) {
    wp_unschedule_event($ts, MMBPL_CRON_HOOK);
  }
});

MMBPL_Logger::log('Plugin loaded');

add_action('plugins_loaded', function () {

  // Created (this hook fires on your site and provides booking_id)
  add_action('bookingpress_after_book_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_book_appointment fired. booking_id=' . (int) $booking_id);

    if (!$booking_id) return;

    MMBPL_Sync::handle_booking_created((int) $booking_id, $args);
  }, 10, 10);

  // Updated/rescheduled hook (keep, but cancellation is handled by polling)
  add_action('bookingpress_after_update_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_update_appointment fired. booking_id=' . (int) $booking_id);

    if (!$booking_id) return;

    MMBPL_Sync::handle_booking_updated((int) $booking_id, $args);
  }, 10, 10);

  // Cancel hook is unreliable on your install, so we do not depend on it.
  // If it does fire, it will still work.
  add_action('bookingpress_after_cancel_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_cancel_appointment fired. booking_id=' . (int) $booking_id);

    if ($booking_id) {
      MMBPL_Sync::handle_booking_cancelled((int) $booking_id, $args);
    }
  }, 10, 99);

});

add_action(MMBPL_CRON_HOOK, function () {
  $settings = get_option(MMBPL_OPT, []);
  $debug = !empty($settings['debug']);

  if (empty($settings['lacrm_api_key'])) {
    if ($debug) MMBPL_Logger::log('Poll: no LACRM API key set, skipping.');
    return;
  }

  // Prevent overlapping poll runs
  if (!mmbpl_acquire_lock('cron_poll', 55)) return;

  try {
    global $wpdb;

    $map_table = $wpdb->prefix . 'mmbpl_map';

    // If mapping table does not exist, nothing to poll
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $map_table));
    if ($exists !== $map_table) {
      if ($debug) MMBPL_Logger::log('Poll: map table not found: ' . $map_table);
      return;
    }

    $limit = isset($settings['recheck_mapped_limit']) ? (int) $settings['recheck_mapped_limit'] : 200;
    $limit = max(25, min(1000, $limit));

    // Pull recent mappings (most recently updated first)
    $rows = $wpdb->get_results(
      $wpdb->prepare("SELECT booking_id, lacrm_event_id FROM {$map_table} ORDER BY updated_at DESC LIMIT %d", $limit),
      ARRAY_A
    );

    if (empty($rows)) return;

    foreach ($rows as $r) {
      $booking_id = (int) ($r['booking_id'] ?? 0);
      $event_id = (string) ($r['lacrm_event_id'] ?? '');

      if (!$booking_id || $event_id === '') continue;

      $bp = MMBPL_BookingPress::get_booking_payload($booking_id);
      if (!$bp) continue;

      $status = $bp['status'] ?? '';

      if (mmbpl_is_cancelled_status($status)) {
        if ($debug) MMBPL_Logger::log('Poll: cancelled detected booking_id=' . $booking_id . ' status=' . $status);
        MMBPL_Sync::handle_booking_cancelled($booking_id, ['source' => 'cron']);
      }
    }
  } finally {
    mmbpl_release_lock('cron_poll');
  }
});

class MMBPL_Sync {

  public static function handle_booking_created($booking_id, $hook_args = []) {
    $booking_id = (int) $booking_id;

    if (!mmbpl_acquire_lock('create_' . $booking_id, 30)) {
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

      // Stop duplicates: if we already have a mapping, do nothing
      $existing_event_id = MMBPL_Map::get_event_id($booking_id);
      if (!empty($existing_event_id)) {
        if ($debug) MMBPL_Logger::log('Create skipped, already mapped. booking_id=' . $booking_id . ' event_id=' . $existing_event_id);
        return;
      }

      $bp = MMBPL_BookingPress::get_booking_payload($booking_id);

      if (!$bp || empty($bp['customer_email'])) {
        MMBPL_Logger::log('No payload or missing customer_email for booking_id=' . $booking_id);
        return;
      }

      if (mmbpl_is_cancelled_status($bp['status'] ?? '')) {
        MMBPL_Logger::log('Booking is cancelled, skipping create. booking_id=' . $booking_id);
        return;
      }

      if ($debug) {
        MMBPL_Logger::log('CREATE booking_id=' . $booking_id . ' payload=' . print_r($bp, true));
      }

      $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp);
      if (!$contact_id) {
        MMBPL_Logger::log('Failed to upsert contact for booking_id=' . $booking_id);
        return;
      }

      $title = self::render_template($settings['event_title_template'] ?? '{service} booking', $bp);

      // Keep your current date/time format if lacrm.php expects Date/Time,
      // but your later code expects StartDate/EndDate. This matches your 1.0.3 style.
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
        // Includes BookingPress note in the description
        'Description' => self::build_summary($bp),
        'Location'    => '',
        'IsAllDay'    => false,
      ]);

      if ($event_id) {
        MMBPL_Map::set_event_id($booking_id, (string) $event_id);
      } else {
        MMBPL_Logger::log('CreateEvent failed for booking_id=' . $booking_id);
      }

      // Optional separate CRM note, same content
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
      mmbpl_release_lock('create_' . $booking_id);
    }
  }

  public static function handle_booking_updated($booking_id, $hook_args = []) {
    $booking_id = (int) $booking_id;

    if (!mmbpl_acquire_lock('update_' . $booking_id, 30)) {
      MMBPL_Logger::log('Update skipped due to lock. booking_id=' . $booking_id);
      return;
    }

    try {
      $settings = get_option(MMBPL_OPT, []);
      $debug = !empty($settings['debug']);
      if (empty($settings['lacrm_api_key'])) return;

      $bp = MMBPL_BookingPress::get_booking_payload($booking_id);
      if (!$bp || empty($bp['customer_email'])) return;

      if (mmbpl_is_cancelled_status($bp['status'] ?? '')) {
        self::handle_booking_cancelled($booking_id, $hook_args);
        return;
      }

      if ($debug) {
        MMBPL_Logger::log('UPDATE booking_id=' . $booking_id . ' payload=' . print_r($bp, true));
      }

      $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp);
      if (!$contact_id) return;

      $existing_event_id = MMBPL_Map::get_event_id($booking_id);

      // Delete and recreate to keep it simple
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
        MMBPL_Map::set_event_id($booking_id, (string) $new_event_id);
      }

    } finally {
      mmbpl_release_lock('update_' . $booking_id);
    }
  }

  public static function handle_booking_cancelled($booking_id, $hook_args = []) {
    $booking_id = (int) $booking_id;

    if (!mmbpl_acquire_lock('cancel_' . $booking_id, 30)) {
      MMBPL_Logger::log('Cancel skipped due to lock. booking_id=' . $booking_id);
      return;
    }

    try {
      $settings = get_option(MMBPL_OPT, []);
      $debug = !empty($settings['debug']);

      if (empty($settings['lacrm_api_key'])) return;
      if (empty($settings['delete_on_cancel'])) return;

      $event_id = MMBPL_Map::get_event_id($booking_id);

      if ($debug) {
        MMBPL_Logger::log('Cancel: mapping lookup booking_id=' . $booking_id . ' event_id=' . ($event_id ? $event_id : 'none'));
      }

      if (!$event_id) return;

      $ok = MMBPL_LACRM::delete_event((string) $event_id);

      if ($ok) {
        MMBPL_Map::delete_mapping($booking_id);
        MMBPL_Logger::log('Cancel: deleted event and removed mapping. booking_id=' . $booking_id);
      } else {
        MMBPL_Logger::log('Cancel: DeleteEvent failed. booking_id=' . $booking_id . ' event_id=' . $event_id);
      }

    } finally {
      mmbpl_release_lock('cancel_' . $booking_id);
    }
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

    // Customer note (if you have one)
    if (!empty($bp['customer_note'])) {
      $lines[] = '';
      $lines[] = 'Customer note:';
      $lines[] = (string) $bp['customer_note'];
    }

    // BookingPress internal note (this is the one you asked to include)
    if (!empty($bp['internal_note'])) {
      $lines[] = '';
      $lines[] = 'BookingPress note:';
      $lines[] = (string) $bp['internal_note'];
    }

    if (isset($bp['status']) && $bp['status'] !== '') {
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