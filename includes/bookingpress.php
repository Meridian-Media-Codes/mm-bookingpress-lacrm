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

    // Determine PK column
    $pk = null;
    foreach (['id','booking_id','appointment_id'] as $c) {
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

    // Find customer_id and service_id fields
    $customer_id = null;
    foreach (['customer_id','bookingpress_customer_id'] as $c) {
      if (isset($booking[$c]) && $booking[$c]) { $customer_id = (int) $booking[$c]; break; }
    }

    $service_id = null;
    foreach (['service_id','bookingpress_service_id'] as $c) {
      if (isset($booking[$c]) && $booking[$c]) { $service_id = (int) $booking[$c]; break; }
    }

    $customer = [];
    $service  = [];

    if (!empty($tables['customer_table']) && $customer_id) {
      $ct = $tables['customer_table'];
      $ccols = $wpdb->get_col("SHOW COLUMNS FROM {$ct}", 0);
      $cset = array_flip($ccols);

      $cpk = null;
      foreach (['id','customer_id'] as $c) {
        if (isset($cset[$c])) { $cpk = $c; break; }
      }
      if ($cpk) {
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE {$cpk}=%d", $customer_id), ARRAY_A) ?: [];
      }
    }

    if (!empty($tables['service_table']) && $service_id) {
      $st = $tables['service_table'];
      $scols = $wpdb->get_col("SHOW COLUMNS FROM {$st}", 0);
      $sset = array_flip($scols);

      $spk = null;
      foreach (['id','service_id'] as $c) {
        if (isset($sset[$c])) { $spk = $c; break; }
      }
      if ($spk) {
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE {$spk}=%d", $service_id), ARRAY_A) ?: [];
      }
    }

    // Appointment date/time fields, try common names
    $appointment_date = '';
    foreach (['appointment_date','bookingpress_appointment_date','start_date','appointment_start_date'] as $c) {
      if (!empty($booking[$c])) { $appointment_date = $booking[$c]; break; }
    }

    $appointment_time = '';
    foreach (['appointment_time','bookingpress_appointment_time','start_time','appointment_start_time'] as $c) {
      if (!empty($booking[$c])) { $appointment_time = $booking[$c]; break; }
    }

    $service_name = '';
    foreach (['service_name','name','title'] as $c) {
      if (!empty($service[$c])) { $service_name = $service[$c]; break; }
    }

    $out = [
      'appointment_date' => $appointment_date,
      'appointment_time' => $appointment_time,
      'service_name'     => $service_name,
    ];

    // Customer fields, try common names
    foreach ([
      'customer_first_name' => ['customer_first_name','first_name'],
      'customer_last_name'  => ['customer_last_name','last_name'],
      'customer_email'      => ['customer_email','email'],
      'customer_phone'      => ['customer_phone','phone','mobile'],
      'customer_note'       => ['customer_note','note','notes'],
    ] as $key => $candidates) {
      foreach ($candidates as $c) {
        if (!empty($customer[$c])) { $out[$key] = $customer[$c]; break; }
      }
      if (!isset($out[$key])) $out[$key] = '';
    }

    if ($debug) {
      MMBPL_Logger::log('Detected tables: ' . print_r($tables, true));
      MMBPL_Logger::log('Booking row keys: ' . implode(',', array_keys($booking)));
      if ($customer) MMBPL_Logger::log('Customer row keys: ' . implode(',', array_keys($customer)));
      if ($service) MMBPL_Logger::log('Service row keys: ' . implode(',', array_keys($service)));
    }

    return $out;
  }
}