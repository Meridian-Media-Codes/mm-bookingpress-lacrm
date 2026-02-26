<?php
/*
Plugin Name: MM BookingPress to LACRM
Description: Sync BookingPress Pro bookings to Less Annoying CRM (create or update contact, create event, add note, delete event on cancel if mapping exists).
Version: 1.0.1
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

/**
 * Custom cron interval (2 minutes).
 */
add_filter('cron_schedules', function ($schedules) {
  if (!isset($schedules['mmbpl_2min'])) {
    $schedules['mmbpl_2min'] = [
      'interval' => 120,
      'display'  => 'Every 2 minutes (MMBPL)'
    ];
  }
  return $schedules;
});

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
      // Set this if you want to stop the first run from trying to sync ALL historical bookings.
      'start_from_latest'      => 1,
    ]);
  }

  // Optionally start from latest booking id so it does not spam old ones
  $settings = get_option(MMBPL_OPT, []);
  if (!empty($settings['start_from_latest'])) {
    $latest = MMBPL_BookingPress::get_latest_booking_id();
    if ($latest > 0) {
      update_option(MMBPL_LAST_OPT, (int) $latest);
      MMBPL_Logger::log('Activation: start_from_latest enabled. Set last_processed_id=' . (int) $latest);
    }
  }

  if (!wp_next_scheduled('mmbpl_poll_bookings')) {
    wp_schedule_event(time() + 30, 'mmbpl_2min', 'mmbpl_poll_bookings');
  }
});

register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled('mmbpl_poll_bookings');
  if ($ts) {
    wp_unschedule_event($ts, 'mmbpl_poll_bookings');
  }
});

add_action('mmbpl_poll_bookings', function () {
  $settings = get_option(MMBPL_OPT, []);
  $debug = !empty($settings['debug']);

  $table = MMBPL_BookingPress::get_booking_table_name();
  $pk    = MMBPL_BookingPress::get_booking_pk_column($table);

  if (!$table || !$pk) {
    if ($debug) MMBPL_Logger::log('Poll: booking table or pk not detected. table=' . (string) $table . ' pk=' . (string) $pk);
    return;
  }

  global $wpdb;

  $last_processed = (int) get_option(MMBPL_LAST_OPT, 0);
  $latest_id = (int) $wpdb->get_var("SELECT MAX({$pk}) FROM {$table}");

  if (!$latest_id || $latest_id <= $last_processed) {
    if ($debug) MMBPL_Logger::log('Poll: no new bookings. latest=' . (int) $latest_id . ' last=' . (int) $last_processed);
    return;
  }

  $new_ids = $wpdb->get_col(
    $wpdb->prepare(
      "SELECT {$pk} FROM {$table} WHERE {$pk} > %d ORDER BY {$pk} ASC LIMIT 25",
      $last_processed
    )
  );

  if (empty($new_ids)) return;

  foreach ($new_ids as $booking_id) {
    $booking_id = (int) $booking_id;

    MMBPL_Logger::log('Poll: detected new booking_id=' . $booking_id);

    MMBPL_Sync::handle_booking_created($booking_id);

    update_option(MMBPL_LAST_OPT, $booking_id);
  }
});

/**
 * Optional: listen for BookingPress hooks too.
 * If they ever fire reliably on your install, great.
 * If not, polling still handles it.
 */
add_action('plugins_loaded', function () {
  add_action('bookingpress_after_book_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);
    MMBPL_Logger::log('HOOK bookingpress_after_book_appointment fired. booking_id=' . (int) $booking_id);
    if ($booking_id) {
      MMBPL_Sync::handle_booking_created((int) $booking_id, $args);
      update_option(MMBPL_LAST_OPT, (int) $booking_id);
    }
  }, 10, 10);

  add_action('bookingpress_after_cancel_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);
    MMBPL_Logger::log('HOOK bookingpress_after_cancel_appointment fired. booking_id=' . (int) $booking_id);
    if ($booking_id) {
      MMBPL_Sync::handle_booking_cancelled((int) $booking_id, $args);
    }
  }, 10, 10);
});

class MMBPL_Sync {

  public static function handle_booking_created($booking_id, $hook_args = []) {
    $settings = get_option(MMBPL_OPT, []);
    $debug = !empty($settings['debug']);

    if (empty($settings['lacrm_api_key'])) {
      MMBPL_Logger::log('No LACRM API key set. Skipping.');
      return;
    }

    $bp = MMBPL_BookingPress::get_booking_payload($booking_id);

    if (!$bp || empty($bp['customer_email'])) {
      MMBPL_Logger::log('No payload or missing customer_email for booking_id=' . (int) $booking_id);
      return;
    }

    if ($debug) {
      MMBPL_Logger::log('CREATE booking_id=' . (int) $booking_id . ' payload=' . print_r($bp, true));
    }

    $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp);

    if (!$contact_id) {
      MMBPL_Logger::log('Failed to upsert contact for booking_id=' . (int) $booking_id);
      return;
    }

    $title = self::render_template($settings['event_title_template'] ?? '{service} booking', $bp);

    // LACRM v2 expects DateTime strings for StartDate/EndDate
    $start = self::make_datetime($bp['appointment_date'] ?? '', $bp['appointment_time'] ?? '');
    $end   = self::make_datetime($bp['appointment_date'] ?? '', $bp['appointment_time'] ?? '', 60);

    if (!$start || !$end) {
      MMBPL_Logger::log('Could not build start/end datetime for booking_id=' . (int) $booking_id);
      return;
    }

    $event_id = MMBPL_LACRM::create_event([
      'ContactId'    => (string) $contact_id,
      'Name'         => $title,
      'StartDate'    => $start,
      'EndDate'      => $end,
      'Description'  => self::build_summary($bp),
      'Location'     => '',
      'IsAllDay'     => false,
    ]);

    if ($event_id) {
      MMBPL_Map::set_event_id((int) $booking_id, (string) $event_id);
    } else {
      MMBPL_Logger::log('CreateEvent failed for booking_id=' . (int) $booking_id);
    }

    if (!empty($settings['add_note'])) {
      $ok = MMBPL_LACRM::create_note([
        'ContactId' => (string) $contact_id,
        'Note'      => self::build_summary($bp),
      ]);

      if (!$ok) {
        MMBPL_Logger::log('CreateNote failed for booking_id=' . (int) $booking_id);
      }
    }
  }

  public static function handle_booking_cancelled($booking_id, $hook_args = []) {
    $settings = get_option(MMBPL_OPT, []);
    if (empty($settings['lacrm_api_key'])) return;
    if (empty($settings['delete_on_cancel'])) return;

    $event_id = MMBPL_Map::get_event_id((int) $booking_id);
    if (!$event_id) return;

    $ok = MMBPL_LACRM::delete_event((string) $event_id);

    if ($ok) {
      MMBPL_Map::delete_mapping((int) $booking_id);
    } else {
      MMBPL_Logger::log('DeleteEvent failed for booking_id=' . (int) $booking_id . ' event_id=' . (string) $event_id);
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

    // LACRM accepts ISO8601 DateTime strings. We will send UTC.
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
      $lines[] = $bp['customer_note'];
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