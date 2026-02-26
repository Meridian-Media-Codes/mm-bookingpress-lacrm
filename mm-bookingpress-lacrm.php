<?php
/*
Plugin Name: MM BookingPress to LACRM
Description: Sync BookingPress Pro bookings to Less Annoying CRM (create/update contact, create event, add note, delete on cancel, resync on update).
Version: 1.0.3
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
      'lacrm_api_key' => '',
      'event_title_template' => '{service} booking',
      'add_note' => 1,
      'delete_on_cancel' => 1,
      'debug' => 0,
      'bp_tables' => [],
    ]);
  }
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
  }, 10, 10);

  // Updated / rescheduled
  add_action('bookingpress_after_update_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_update_appointment fired. booking_id=' . (int) $booking_id);

    if ($booking_id) {
      MMBPL_Sync::handle_booking_updated((int) $booking_id, $args);
    }
  }, 10, 10);

  // Cancelled
  add_action('bookingpress_after_cancel_appointment', function () {
  $args = func_get_args();

  MMBPL_Logger::log('HOOK bookingpress_after_cancel_appointment fired. raw_args=' . print_r($args, true));

  $booking_id = MMBPL_BookingPress::extract_booking_id($args);

  // Fallback: sometimes BookingPress sends the id via POST/REQUEST
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

      if (!$bp || empty($bp['customer_email'])) {
        MMBPL_Logger::log('No payload or missing customer_email for booking_id=' . $booking_id);
        return;
      }

      if (self::is_cancelled_status($bp['status'] ?? '')) {
        MMBPL_Logger::log('Booking is cancelled, skipping create. booking_id=' . $booking_id);
        return;
      }

      $existing = MMBPL_Map::get_mapping($booking_id);
      $current_hash = self::booking_hash($bp);

      // If already mapped and unchanged, do nothing
      if ($existing && !empty($existing['event_id']) && !empty($existing['booking_hash'])) {
        if (hash_equals((string) $existing['booking_hash'], (string) $current_hash)) {
          if ($debug) MMBPL_Logger::log('Create skipped, already synced. booking_id=' . $booking_id);
          return;
        }
      }

      // If mapped but changed, treat as update
      if ($existing && !empty($existing['event_id'])) {
        self::handle_booking_updated($booking_id, $hook_args);
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
        MMBPL_Map::set_mapping($booking_id, (string) $event_id, $current_hash);
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

      // Delete and recreate for clean reschedule/edit
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

      // Add internal note as separate CRM note (optional)
      if (!empty($settings['add_note']) && !empty($bp['internal_note'])) {
        MMBPL_LACRM::create_note([
          'ContactId' => (string) $contact_id,
          'Note'      => "Internal appointment note:\n" . (string) $bp['internal_note'],
        ]);
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

      if (!$event_id) {
        MMBPL_Logger::log('Cancel: no mapped event to delete. booking_id=' . $booking_id);
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

  // Numeric statuses used by some BookingPress installs
  if (ctype_digit($raw)) {
    $code = (int) $raw;

    // These are common patterns:
    // 1 often means approved or pending on some setups
    // Cancelled is commonly 3 or 4 depending on configuration
    return in_array($code, [3, 4], true);
  }

  $s = strtolower($raw);
  return in_array($s, [
    'cancelled','canceled','cancel','cancelled_by_admin','cancelled_by_customer','rejected'
  ], true);
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
      $lines[] = 'BookingPress note:';
      $lines[] = (string) $bp['internal_note'];
    }

    if (!empty($bp['status'])) {
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