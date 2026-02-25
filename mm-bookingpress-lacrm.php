<?php
/*
Plugin Name: MM BookingPress to LACRM
Description: Sync BookingPress Pro bookings to Less Annoying CRM (create contact, create event, add note, delete on cancel).
Version: 1.0.0
Author: Meridian Media
*/

if (!defined('ABSPATH')) exit;

define('MMBPL_PLUGIN_FILE', __FILE__);
define('MMBPL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MMBPL_OPT', 'mmbpl_settings');
define('MMBPL_DB_VERSION', '1.0');

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

add_action('plugins_loaded', function () {

  // Booking created
  add_action('bookingpress_after_book_appointment', function ($inserted_booking_id = 0) {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    // Always log this so we can confirm hook firing
    MMBPL_Logger::log('HOOK bookingpress_after_book_appointment fired. booking_id=' . (int) $booking_id);

    if (!$booking_id) return;

    MMBPL_Sync::handle_booking_created($booking_id, $args);
  }, 10, 3);

  // Booking updated
  add_action('bookingpress_after_update_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    if (!$booking_id) return;

    MMBPL_Sync::handle_booking_updated($booking_id, $args);
  }, 10, 10);

  // Booking cancelled
  add_action('bookingpress_after_cancel_appointment', function () {
    $args = func_get_args();
    $booking_id = MMBPL_BookingPress::extract_booking_id($args);

    if (!$booking_id) return;

    MMBPL_Sync::handle_booking_cancelled($booking_id, $args);
  }, 10, 10);
});

class MMBPL_Sync {

  public static function handle_booking_created($booking_id, $hook_args = []) {
    $settings = get_option(MMBPL_OPT, []);

    if (empty($settings['lacrm_api_key'])) {
      MMBPL_Logger::log('No LACRM API key set. Skipping.');
      return;
    }

    $bp = MMBPL_BookingPress::get_booking_payload($booking_id);

    if (!$bp || empty($bp['customer_email'])) {
      MMBPL_Logger::log('No payload or missing customer_email for booking_id=' . (int) $booking_id);
      return;
    }

    if (!empty($settings['debug'])) {
      MMBPL_Logger::log('CREATE booking_id=' . (int) $booking_id . ' payload=' . print_r($bp, true));
    }

    $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp);

    if (!$contact_id) {
      MMBPL_Logger::log('Failed to upsert contact for booking_id=' . (int) $booking_id);
      return;
    }

    $title = self::render_template($settings['event_title_template'] ?? '{service} booking', $bp);

    $event_id = MMBPL_LACRM::create_event([
      'ContactId' => $contact_id,
      'Subject'   => $title,
      'Date'      => $bp['appointment_date'] ?? '',
      'Time'      => $bp['appointment_time'] ?? '',
      'Details'   => self::build_summary($bp),
    ]);

    if ($event_id) {
      MMBPL_Map::set_event_id((int) $booking_id, (string) $event_id);
    } else {
      MMBPL_Logger::log('CreateEvent failed for booking_id=' . (int) $booking_id);
    }

    if (!empty($settings['add_note'])) {
      $ok = MMBPL_LACRM::create_note([
        'ContactId' => $contact_id,
        'Note'      => self::build_summary($bp),
      ]);

      if (!$ok) {
        MMBPL_Logger::log('CreateNote failed for booking_id=' . (int) $booking_id);
      }
    }
  }

  public static function handle_booking_updated($booking_id, $hook_args = []) {
    $settings = get_option(MMBPL_OPT, []);
    if (empty($settings['lacrm_api_key'])) return;

    $bp = MMBPL_BookingPress::get_booking_payload($booking_id);
    if (!$bp || empty($bp['customer_email'])) return;

    if (!empty($settings['debug'])) {
      MMBPL_Logger::log('UPDATE booking_id=' . (int) $booking_id . ' payload=' . print_r($bp, true));
    }

    $contact_id = MMBPL_LACRM::upsert_contact_by_email($bp);
    if (!$contact_id) return;

    $existing_event_id = MMBPL_Map::get_event_id((int) $booking_id);
    $title = self::render_template($settings['event_title_template'] ?? '{service} booking', $bp);

    if ($existing_event_id) {
      MMBPL_LACRM::delete_event($existing_event_id);

      $new_event_id = MMBPL_LACRM::create_event([
        'ContactId' => $contact_id,
        'Subject'   => $title,
        'Date'      => $bp['appointment_date'] ?? '',
        'Time'      => $bp['appointment_time'] ?? '',
        'Details'   => self::build_summary($bp),
      ]);

      if ($new_event_id) {
        MMBPL_Map::set_event_id((int) $booking_id, (string) $new_event_id);
      } else {
        MMBPL_Logger::log('Recreate event failed for booking_id=' . (int) $booking_id);
      }
    } else {
      self::handle_booking_created($booking_id, $hook_args);
    }
  }

  public static function handle_booking_cancelled($booking_id, $hook_args = []) {
    $settings = get_option(MMBPL_OPT, []);
    if (empty($settings['lacrm_api_key'])) return;
    if (empty($settings['delete_on_cancel'])) return;

    $event_id = MMBPL_Map::get_event_id((int) $booking_id);

    if (!empty($settings['debug'])) {
      MMBPL_Logger::log('CANCEL booking_id=' . (int) $booking_id . ' event_id=' . ($event_id ?: 'none'));
    }

    if (!$event_id) return;

    $ok = MMBPL_LACRM::delete_event($event_id);

    if ($ok) {
      MMBPL_Map::delete_mapping((int) $booking_id);
    } else {
      MMBPL_Logger::log('DeleteEvent failed for booking_id=' . (int) $booking_id . ' event_id=' . $event_id);
    }
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

    return trim(strtr($tpl, $replacements));
  }
}