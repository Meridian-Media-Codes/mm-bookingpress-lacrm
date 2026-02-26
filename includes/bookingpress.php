<?php
if (!defined('ABSPATH')) exit;

class MMBPL_BookingPress {

  public static function extract_booking_id($args) {
    foreach ($args as $a) {
      if (is_numeric($a) && (int) $a > 0) return (int) $a;
    }
    return 0;
  }

  public static function get_booking_payload($booking_id) {
    $settings = get_option(MMBPL_OPT, []);
    $tables = $settings['bp_tables'] ?? [];

    if (empty($tables)) {
      $tables = self::discover_tables();
      $settings['bp_tables'] = $tables;
      update_option(MMBPL_OPT, $settings);
    }

    $row = self::fetch_booking_row($booking_id, $tables);

    if (!$row) {
      $tables = self::discover_tables(true);
      $settings['bp_tables'] = $tables;
      update_option(MMBPL_OPT, $settings);
      $row = self::fetch_booking_row($booking_id, $tables);
    }

    if (!$row) return false;

    $payload = [
      'customer_first_name' => $row['customer_first_name'] ?? '',
      'customer_last_name'  => $row['customer_last_name'] ?? '',
      'customer_email'      => $row['customer_email'] ?? '',
      'customer_phone'      => $row['customer_phone'] ?? '',
      'customer_note'       => $row['customer_note'] ?? '',
      'service_name'        => $row['service_name'] ?? '',
      'appointment_date'    => $row['appointment_date'] ?? '',
      'appointment_time'    => $row['appointment_time'] ?? '',
      'status'              => $row['status'] ?? '',
      'internal_note'       => $row['internal_note'] ?? '',
    ];

    return $payload;
  }

  public static function resolve_booking_id($maybe_id) {
  global $wpdb;

  $maybe_id = (int) $maybe_id;
  if ($maybe_id <= 0) return 0;

  $table = $wpdb->get_var("SHOW TABLES LIKE '%bookingpress_appointment_bookings%'");
  if (!$table) return $maybe_id;

  // If it exists as the booking PK, use it
  $pk = 'bookingpress_appointment_booking_id';
  $exists = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(1) FROM {$table} WHERE {$pk}=%d",
    $maybe_id
  ));
  if ($exists > 0) return $maybe_id;

  // Otherwise, try common appointment id columns and map back to booking PK
  $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
  $colset = $cols ? array_flip($cols) : [];

  foreach (['bookingpress_appointment_id', 'appointment_id'] as $c) {
    if (!isset($colset[$c])) continue;

    $bid = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT {$pk} FROM {$table} WHERE {$c}=%d LIMIT 1",
      $maybe_id
    ));
    if ($bid > 0) return $bid;
  }

  // Fall back to original value if we cannot resolve
  return $maybe_id;
}

  private static function discover_tables($force = false) {
    global $wpdb;

    $like = '%' . $wpdb->esc_like('bookingpress') . '%';
    $raw = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));

    $tables = [
      'booking_table' => '',
      'customer_table' => '',
      'service_table' => '',
    ];

    if (empty($raw)) return $tables;

    foreach ($raw as $t) {
      $cols = $wpdb->get_col("SHOW COLUMNS FROM {$t}", 0);
      if (!$cols) continue;

      $colset = array_flip($cols);

      $booking_score = 0;
      foreach (['appointment_date', 'bookingpress_appointment_date', 'start_date', 'appointment_start_date'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['appointment_time', 'bookingpress_appointment_time', 'start_time', 'appointment_start_time'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['service_id', 'bookingpress_service_id'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['customer_id', 'bookingpress_customer_id'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['id', 'booking_id', 'appointment_id', 'bookingpress_appointment_booking_id'] as $c) {
        if (isset($colset[$c])) $booking_score += 1;
      }

      $customer_score = 0;
      foreach (['email', 'customer_email', 'bookingpress_customer_email'] as $c) {
        if (isset($colset[$c])) $customer_score += 3;
      }
      foreach (['first_name', 'customer_first_name', 'bookingpress_customer_firstname'] as $c) {
        if (isset($colset[$c])) $customer_score += 2;
      }
      foreach (['last_name', 'customer_last_name', 'bookingpress_customer_lastname'] as $c) {
        if (isset($colset[$c])) $customer_score += 2;
      }
      foreach (['phone', 'customer_phone', 'bookingpress_customer_phone'] as $c) {
        if (isset($colset[$c])) $customer_score += 1;
      }

      $service_score = 0;
      foreach (['name', 'service_name', 'title', 'bookingpress_service_name'] as $c) {
        if (isset($colset[$c])) $service_score += 2;
      }

      if ($booking_score >= 4 && empty($tables['booking_table'])) $tables['booking_table'] = $t;
      if ($customer_score > 4 && empty($tables['customer_table'])) $tables['customer_table'] = $t;
      if ($service_score > 2 && empty($tables['service_table'])) $tables['service_table'] = $t;
    }

    return $tables;
  }

  private static function fetch_booking_row($booking_id, $tables) {
    global $wpdb;

    $settings = get_option(MMBPL_OPT, []);
    $debug = !empty($settings['debug']);

    $bt = $tables['booking_table'] ?? '';
    if (!$bt) {
      if ($debug) MMBPL_Logger::log('No booking_table detected. tables=' . print_r($tables, true));
      return false;
    }

    $bcols = $wpdb->get_col("SHOW COLUMNS FROM {$bt}", 0);
    if (!$bcols) return false;
    $bset = array_flip($bcols);

    $pk = null;
    foreach ([
      'bookingpress_appointment_booking_id',
      'bookingpress_booking_id',
      'bookingpress_entry_id',
      'bookingpress_order_id',
      'id',
      'booking_id',
      'appointment_id'
    ] as $c) {
      if (isset($bset[$c])) { $pk = $c; break; }
    }

    if (!$pk) {
      if ($debug) MMBPL_Logger::log("No primary id column found on {$bt}. cols=" . implode(',', $bcols));
      return false;
    }

    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE {$pk}=%d", (int) $booking_id), ARRAY_A);
    if (!$booking) {
      if ($debug) MMBPL_Logger::log("No booking row found in {$bt} for {$pk}={$booking_id}");
      return false;
    }

    $appointment_date = $booking['bookingpress_appointment_date'] ?? ($booking['appointment_date'] ?? '');
    $appointment_time = $booking['bookingpress_appointment_time'] ?? ($booking['appointment_time'] ?? '');

    $service_name = $booking['bookingpress_service_name'] ?? ($booking['service_name'] ?? '');

    $out = [
      'appointment_date'     => (string) $appointment_date,
      'appointment_time'     => (string) $appointment_time,
      'service_name'         => (string) $service_name,
      'customer_first_name'  => (string) ($booking['bookingpress_customer_firstname'] ?? ($booking['customer_first_name'] ?? '')),
      'customer_last_name'   => (string) ($booking['bookingpress_customer_lastname'] ?? ($booking['customer_last_name'] ?? '')),
      'customer_email'       => (string) ($booking['bookingpress_customer_email'] ?? ($booking['customer_email'] ?? '')),
      'customer_phone'       => (string) ($booking['bookingpress_customer_phone'] ?? ($booking['customer_phone'] ?? '')),
      'customer_note'        => (string) ($booking['bookingpress_customer_note'] ?? ($booking['customer_note'] ?? '')),
      'status'               => (string) ($booking['bookingpress_appointment_status'] ?? ($booking['appointment_status'] ?? '')),
      'internal_note'        => (string) ($booking['bookingpress_appointment_internal_note'] ?? ($booking['appointment_internal_note'] ?? '')),
    ];

    if ($debug) {
      MMBPL_Logger::log('Detected tables: ' . print_r($tables, true));
      MMBPL_Logger::log('Booking PK column: ' . $pk);
      MMBPL_Logger::log('Booking row keys: ' . implode(',', array_keys($booking)));
      MMBPL_Logger::log('Normalized payload: ' . print_r($out, true));
    }

    return $out;
  }
}