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
      // One re-discovery in case BookingPress updated table names
      $tables = self::discover_tables(true);
      $settings['bp_tables'] = $tables;
      update_option(MMBPL_OPT, $settings);
      $row = self::fetch_booking_row($booking_id, $tables);
    }

    if (!$row) return false;

    // Normalize output keys used by sync
    $payload = [
      'customer_first_name' => $row['customer_first_name'] ?? '',
      'customer_last_name'  => $row['customer_last_name'] ?? '',
      'customer_email'      => $row['customer_email'] ?? '',
      'customer_phone'      => $row['customer_phone'] ?? '',
      'customer_note'       => $row['customer_note'] ?? '',
      'service_name'        => $row['service_name'] ?? '',
      'appointment_date'    => $row['appointment_date'] ?? '',
      'appointment_time'    => $row['appointment_time'] ?? '',
    ];

    return $payload;
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

    // Heuristic scoring by columns
    foreach ($raw as $t) {
      $cols = $wpdb->get_col("SHOW COLUMNS FROM {$t}", 0);
      if (!$cols) continue;

      $colset = array_flip($cols);

      // booking table: has date/time and service and customer
      $booking_score = 0;
      foreach (['appointment_date', 'bookingpress_appointment_date', 'start_date', 'appointment_start_date'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['appointment_time', 'bookingpress_appointment_time', 'start_time', 'appointment_start_time'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['service_id','bookingpress_service_id'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['customer_id','bookingpress_customer_id'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      if (isset($colset['id']) || isset($colset['booking_id']) || isset($colset['appointment_id'])) {
        $booking_score += 1;
      }

      // customer table: has email
      $customer_score = 0;
      foreach (['email','customer_email'] as $c) {
        if (isset($colset[$c])) $customer_score += 3;
      }
      foreach (['first_name','customer_first_name'] as $c) {
        if (isset($colset[$c])) $customer_score += 2;
      }
      foreach (['last_name','customer_last_name'] as $c) {
        if (isset($colset[$c])) $customer_score += 2;
      }
      foreach (['phone','customer_phone'] as $c) {
        if (isset($colset[$c])) $customer_score += 1;
      }

      // service table: has name/title
      $service_score = 0;
      foreach (['name','service_name','title'] as $c) {
        if (isset($colset[$c])) $service_score += 2;
      }

      // Pick best matches
      if ($booking_score > 4 && empty($tables['booking_table'])) $tables['booking_table'] = $t;
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

    // Determine PK column (BookingPress Pro uses bookingpress_appointment_booking_id)
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

    
    // Appointment date/time fields (your table has these)
$appointment_date = $booking['bookingpress_appointment_date'] ?? ($booking['appointment_date'] ?? '');
$appointment_time = $booking['bookingpress_appointment_time'] ?? ($booking['appointment_time'] ?? '');

// Service name is in the booking row on your install
$service_name = $booking['bookingpress_service_name'] ?? ($booking['service_name'] ?? '');

// Customer fields are also in the booking row on your install
$out = [
  'appointment_date'     => (string) $appointment_date,
  'appointment_time'     => (string) $appointment_time,
  'service_name'         => (string) $service_name,
  'customer_first_name'  => (string) ($booking['bookingpress_customer_firstname'] ?? ($booking['customer_first_name'] ?? '')),
  'customer_last_name'   => (string) ($booking['bookingpress_customer_lastname'] ?? ($booking['customer_last_name'] ?? '')),
  'customer_email'       => (string) ($booking['bookingpress_customer_email'] ?? ($booking['customer_email'] ?? '')),
  'customer_phone'       => (string) ($booking['bookingpress_customer_phone'] ?? ($booking['customer_phone'] ?? '')),
  'customer_note'        => (string) ($booking['bookingpress_customer_note'] ?? ($booking['customer_note'] ?? '')),
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