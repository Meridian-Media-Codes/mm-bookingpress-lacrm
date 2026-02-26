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

define('MMBPL_CRON_HOOK', 'mmbpl_cron_tick');
define('MMBPL_CRON_SCHEDULE', 'mmbpl_every_minute');

require_once MMBPL_PLUGIN_DIR . 'includes/logger.php';
require_once MMBPL_PLUGIN_DIR . 'includes/map.php';
require_once MMBPL_PLUGIN_DIR . 'includes/lacrm.php';
require_once MMBPL_PLUGIN_DIR . 'includes/bookingpress.php';
require_once MMBPL_PLUGIN_DIR . 'includes/admin.php';

/**
 * Schedules
 */
add_filter('cron_schedules', function ($schedules) {
  if (!isset($schedules[MMBPL_CRON_SCHEDULE])) {
    $schedules[MMBPL_CRON_SCHEDULE] = [
      'interval' => 60,
      'display'  => 'MMBPL every minute',
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
      'recheck_mapped_limit'   => 200,
      // Set this once you confirm your real cancel codes.
      // Your create logs show status "1" on new bookings.
      // Common cancel codes are 3 or 4, but confirm on your site.
      'cancel_status_values'   => '3,4,cancelled,canceled',
      // How many new ids cron will process per run
      'cron_new_limit'         => 25,
    ]);
  }

  if (!wp_next_scheduled(MMBPL_CRON_HOOK)) {
    wp_schedule_event(time() + 60, MMBPL_CRON_SCHEDULE, MMBPL_CRON_HOOK);
  }
});

register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled(MMBPL_CRON_HOOK);
  if ($ts) {
    wp_unschedule_event($ts, MMBPL_CRON_HOOK);
  }
});

/**
 * Log "plugin loaded" without spamming the log on every request.
 */
add_action('plugins_loaded', function () {
  if (!get_transient('mmbpl_loaded_ping')) {
    MMBPL_Logger::log('Plugin loaded');
    set_transient('mmbpl_loaded_ping', 1, 3600);
  }
});

/**
 * BookingPress hooks.
 * Create and update are useful. Cancel is unreliable on many installs, so cancel is handled by cron status watcher.
 */
add_action('plugins_loaded', function () {

  // Created
  add_action('bookingpress_after_book_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_book_appointment fired. booking_id=' . (int) $booking_id);

    if ($booking_id) {
      MMBPL_Sync::handle_booking_created((int) $booking_id, ['source' => 'hook_create', 'args' => $args]);
      update_option(MMBPL_LAST_OPT, (int) $booking_id);
    }
  }, 10, 10);

  // Updated / rescheduled
  add_action('bookingpress_after_update_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_update_appointment fired. booking_id=' . (int) $booking_id);

    if ($booking_id) {
      MMBPL_Sync::handle_booking_updated((int) $booking_id, ['source' => 'hook_update', 'args' => $args]);
    }
  }, 10, 10);

  // Cancel hook kept for logging only. Do not rely on it for deletes.
  add_action('bookingpress_after_cancel_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    MMBPL_Logger::log('HOOK bookingpress_after_cancel_appointment fired. booking_id=' . (int) $booking_id . ' raw_args=' . print_r($args, true));

    if ($booking_id) {
      MMBPL_Sync::handle_booking_cancelled((int) $booking_id, ['source' => 'hook_cancel', 'args' => $args]);
    }
  }, 10, 99);

});

/**
 * Cron watcher.
 * 1) Sync new bookings by id.
 * 2) Recheck recent mapped bookings for cancel or changes.
 */
add_action(MMBPL_CRON_HOOK, function () {

  $settings = get_option(MMBPL_OPT, []);
  $debug = !empty($settings['debug']);

  if (empty($settings['lacrm_api_key'])) {
    if ($debug) MMBPL_Logger::log('Cron: no API key, skipping.');
    return;
  }

  global $wpdb;

  // Try to locate the bookings table on this install.
  $table = $wpdb->get_var("SHOW TABLES LIKE '%bookingpress_appointment_bookings%'");
  if (!$table) {
    if ($debug) MMBPL_Logger::log('Cron: bookings table not found.');
    return;
  }

  $pk = 'bookingpress_appointment_booking_id';

  // A) Sync new bookings
  $last_processed = (int) get_option(MMBPL_LAST_OPT, 0);
  $limit_new = (int) ($settings['cron_new_limit'] ?? 25);
  $limit_new = max(1, min(200, $limit_new));

  $new_ids = $wpdb->get_col(
    $wpdb->prepare(
      "SELECT {$pk} FROM {$table} WHERE {$pk} > %d ORDER BY {$pk} ASC LIMIT {$limit_new}",
      $last_processed
    )
  );

  if (!empty($new_ids)) {
    foreach ($new_ids as $bid) {
      $bid = (int) $bid;
      MMBPL_Logger::log('Cron: new booking detected. booking_id=' . $bid);
      MMBPL_Sync::handle_booking_created($bid, ['source' => 'cron_new']);
      update_option(MMBPL_LAST_OPT, $bid);
    }
  }

  // B) Recheck mapped bookings
  $limit = (int) ($settings['recheck_mapped_limit'] ?? 200);
  $limit = max(25, min(1000, $limit));

  $mapped_ids = MMBPL_Map::list_booking_ids_with_events($limit);
  if (empty($mapped_ids)) return;

  foreach ($mapped_ids as $bid) {
    $bid = (int) $bid;

    $bp = MMBPL_BookingPress::get_booking_payload($bid);
    if (!$bp) continue;

    // If cancelled in BookingPress, delete in CRM
    if (MMBPL_Sync::is_cancelled_status($bp['status'] ?? null)) {
      MMBPL_Logger::log('Cron: cancel detected. booking_id=' . $bid . ' status=' . (string) ($bp['status'] ?? ''));
      MMBPL_Sync::handle_booking_cancelled($bid, ['source' => 'cron_cancel']);
      continue;
    }

    // If changed, resync
    $map = MMBPL_Map::get_mapping($bid);
    if (!$map) continue;

    $current_hash = MMBPL_Sync::booking_hash($bp);
    $stored_hash = (string) ($map['booking_hash'] ?? '');

    if ($stored_hash && $current_hash !== $stored_hash) {
      if ($debug) MMBPL_Logger::log('Cron: change detected. booking_id=' . $bid);
      MMBPL_Sync::handle_booking_updated($bid, ['source' => 'cron_update']);
    }
  }

});

/**
 * Sync logic
 */
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

  public static function handle_booking_created($booking_id, $ctx = []) {
    $booking_id = (int) $booking_id;

    if (!$booking_id) return;

    // Short lock to stop duplicates from multiple requests.
    if (!self::acquire_lock($booking_id, 90)) {
      MMBPL_Logger::log('Create skipped due to lock. booking_id=' . $booking_id);
      return;
    }

    try {
      $settings = get_option(MMBPL_OPT, []);
      $debug = !empty($settings['debug']);

      if (empty($settings['lacrm_api_key'])) {
        if ($debug) MMBPL_Logger::log('No LACRM API key set. Skipping create.');
        return;
      }

      $bp = MMBPL_BookingPress::get_booking_payload($booking_id);

      if (!$bp || empty($bp['customer_email'])) {
        MMBPL_Logger::log('No payload or missing customer_email for booking_id=' . $booking_id);
        return;
      }

      if (self::is_cancelled_status($bp['status'] ?? null)) {
        MMBPL_Logger::log('Booking is cancelled, skipping create. booking_id=' . $booking_id);
        return;
      }

      $current_hash = self::booking_hash($bp);
      $existing = MMBPL_Map::get_mapping($booking_id);

      // Idempotent behaviour: if we already synced this exact state, do nothing.
      if ($existing && !empty($existing['event_id']) && !empty($existing['booking_hash'])) {
        if (hash_equals((string) $existing['booking_hash'], (string) $current_hash)) {
          if ($debug) MMBPL_Logger::log('Create skipped, already synced. booking_id=' . $booking_id);
          return;
        }
      }

      // If it already has an event but the hash changed, treat it as update.
      if ($existing && !empty($existing['event_id'])) {
        self::handle_booking_updated($booking_id, ['source' => 'create_promoted_to_update']);
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

      // Use the same CreateEvent shape you already had working in your logs.
      $event_id = MMBPL_LACRM::create_event([
        'ContactId' => (string) $contact_id,
        'Subject'   => (string) $title,
        'Date'      => (string) ($bp['appointment_date'] ?? ''),
        'Time'      => (string) ($bp['appointment_time'] ?? ''),
        // Include both notes in the event details
        'Details'   => self::build_summary($bp),
      ]);

      if ($event_id) {
        MMBPL_Map::set_mapping($booking_id, (string) $event_id, (string) $current_hash);
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

  public static function handle_booking_updated($booking_id, $ctx = []) {
    $booking_id = (int) $booking_id;
    if (!$booking_id) return;

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

      if (self::is_cancelled_status($bp['status'] ?? null)) {
        self::handle_booking_cancelled($booking_id, ['source' => 'update_detected_cancel']);
        return;
      }

      if ($debug) {
        MMBPL_Logger::log('UPDATE booking_id=' . $booking_id . ' payload=' . print_r($bp, true));
      }

      $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp);
      if (!$contact_id) return;

      $existing_event_id = MMBPL_Map::get_event_id($booking_id);

      // Delete then recreate for a clean reschedule/edit.
      if ($existing_event_id) {
        MMBPL_LACRM::delete_event((string) $existing_event_id);
      }

      $title = self::render_template($settings['event_title_template'] ?? '{service} booking', $bp);

      $new_event_id = MMBPL_LACRM::create_event([
        'ContactId' => (string) $contact_id,
        'Subject'   => (string) $title,
        'Date'      => (string) ($bp['appointment_date'] ?? ''),
        'Time'      => (string) ($bp['appointment_time'] ?? ''),
        'Details'   => self::build_summary($bp),
      ]);

      if ($new_event_id) {
        $hash = self::booking_hash($bp);
        MMBPL_Map::set_mapping($booking_id, (string) $new_event_id, (string) $hash);
      } else {
        MMBPL_Logger::log('Update: CreateEvent failed for booking_id=' . $booking_id);
      }

      // Optional separate note with internal note only
      if (!empty($settings['add_note']) && !empty($bp['internal_note'])) {
        MMBPL_LACRM::create_note([
          'ContactId' => (string) $contact_id,
          'Note'      => "BookingPress note:\n" . (string) $bp['internal_note'],
        ]);
      }

    } finally {
      self::release_lock($booking_id);
    }
  }

  public static function handle_booking_cancelled($booking_id, $ctx = []) {
    $booking_id = (int) $booking_id;
    if (!$booking_id) return;

    if (!self::acquire_lock($booking_id, 60)) {
      MMBPL_Logger::log('Cancel skipped due to lock. booking_id=' . $booking_id);
      return;
    }

    try {
      $settings = get_option(MMBPL_OPT, []);
      if (empty($settings['lacrm_api_key'])) return;
      if (empty($settings['delete_on_cancel'])) return;

      $event_id = MMBPL_Map::get_event_id($booking_id);
      MMBPL_Logger::log('Cancel: mapping lookup booking_id=' . $booking_id . ' event_id=' . ($event_id ? $event_id : 'none'));

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
    $settings = get_option(MMBPL_OPT, []);
    $raw = trim((string) $status);
    if ($raw === '') return false;

    $list = (string) ($settings['cancel_status_values'] ?? '3,4,cancelled,canceled');
    $vals = array_values(array_filter(array_map('trim', explode(',', $list))));

    // Numeric match
    if (ctype_digit($raw)) {
      $code = (int) $raw;
      foreach ($vals as $v) {
        if (ctype_digit($v) && (int) $v === $code) return true;
      }
      return false;
    }

    // String match
    $s = strtolower($raw);
    foreach ($vals as $v) {
      if ($v !== '' && strtolower($v) === $s) return true;
    }

    return false;
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

  private static function build_summary($bp) {
    $lines = [];

    if (!empty($bp['service_name'])) $lines[] = 'Service: ' . (string) $bp['service_name'];
    if (!empty($bp['appointment_date'])) $lines[] = 'Date: ' . (string) $bp['appointment_date'];
    if (!empty($bp['appointment_time'])) $lines[] = 'Time: ' . (string) $bp['appointment_time'];

    $name = trim((string) ($bp['customer_first_name'] ?? '') . ' ' . (string) ($bp['customer_last_name'] ?? ''));
    if ($name !== '') $lines[] = 'Customer: ' . $name;

    if (!empty($bp['customer_email'])) $lines[] = 'Email: ' . (string) $bp['customer_email'];
    if (!empty($bp['customer_phone'])) $lines[] = 'Phone: ' . (string) $bp['customer_phone'];

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
      '{service}' => (string) ($bp['service_name'] ?? ''),
      '{date}'    => (string) ($bp['appointment_date'] ?? ''),
      '{time}'    => (string) ($bp['appointment_time'] ?? ''),
      '{email}'   => (string) ($bp['customer_email'] ?? ''),
      '{name}'    => trim((string) ($bp['customer_first_name'] ?? '') . ' ' . (string) ($bp['customer_last_name'] ?? '')),
    ];
    return trim(strtr((string) $tpl, $replacements));
  }
}