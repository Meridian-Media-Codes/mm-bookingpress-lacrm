<?php
if (!defined('ABSPATH')) exit;

class MMBPL_BookingPress {

  // Your two custom fields
  // Field IDs are shown in your form_fields table but we do not need them for lookup.
  const METAKEY_ADDRESS1  = 'text_9Vv7N';
  const METAKEY_POSTCODE  = 'text_SIzYG'; // note: capital i, matches your screenshot

  public static function extract_booking_id($args) {
    foreach ($args as $a) {
      if (is_numeric($a) && (int) $a > 0) return (int) $a;
    }
    return 0;
  }

  public static function resolve_booking_id($maybe_id) {
    global $wpdb;

    $maybe_id = (int) $maybe_id;
    if ($maybe_id <= 0) return 0;

    $table = $wpdb->get_var("SHOW TABLES LIKE '%bookingpress_appointment_bookings%'");
    if (!$table) return $maybe_id;

    $pk = 'bookingpress_appointment_booking_id';

    // If it exists as the booking PK, use it
    $exists = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(1) FROM {$table} WHERE {$pk}=%d",
      $maybe_id
    ));
    if ($exists > 0) return $maybe_id;

    // Otherwise, try appointment id columns and map back to booking PK
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

    return $maybe_id;
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

      // Address fields
      'customer_address_1'  => $row['customer_address_1'] ?? '',
      'customer_address_2'  => $row['customer_address_2'] ?? '',
      'customer_city'       => $row['customer_city'] ?? '',
      'customer_state'      => $row['customer_state'] ?? '',
      'customer_postcode'   => $row['customer_postcode'] ?? '',
      'customer_country'    => $row['customer_country'] ?? '',

      // Internal ids used for meta lookups
      '_booking_id'         => (int) ($row['_booking_id'] ?? 0),
      '_appointment_id'     => (int) ($row['_appointment_id'] ?? 0),
      '_customer_id'        => (int) ($row['_customer_id'] ?? 0),
      '_entry_id'           => (int) ($row['_entry_id'] ?? 0),
    ];

    self::hydrate_address_from_appointment_meta($payload);

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

    foreach ($raw as $t) {
      $cols = $wpdb->get_col("SHOW COLUMNS FROM {$t}", 0);
      if (!$cols) continue;

      $colset = array_flip($cols);

      $booking_score = 0;
      foreach (['bookingpress_appointment_date', 'appointment_date'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['bookingpress_appointment_time', 'appointment_time'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['bookingpress_customer_email', 'customer_email'] as $c) {
        if (isset($colset[$c])) $booking_score += 2;
      }
      foreach (['bookingpress_appointment_booking_id', 'bookingpress_entry_id', 'bookingpress_customer_id', 'id'] as $c) {
        if (isset($colset[$c])) $booking_score += 1;
      }

      $customer_score = 0;
      foreach (['bookingpress_user_email', 'bookingpress_customer_email', 'email'] as $c) {
        if (isset($colset[$c])) $customer_score += 3;
      }

      $service_score = 0;
      foreach (['bookingpress_service_name', 'service_name', 'name', 'title'] as $c) {
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

    $booking = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$bt} WHERE {$pk}=%d", (int) $booking_id),
      ARRAY_A
    );

    if (!$booking) {
      if ($debug) MMBPL_Logger::log("No booking row found in {$bt} for {$pk}={$booking_id}");
      return false;
    }

    $appointment_date = $booking['bookingpress_appointment_date'] ?? ($booking['appointment_date'] ?? '');
    $appointment_time = $booking['bookingpress_appointment_time'] ?? ($booking['appointment_time'] ?? '');
    $service_name     = $booking['bookingpress_service_name'] ?? ($booking['service_name'] ?? '');

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

      // Address defaults
      'customer_address_1'   => '',
      'customer_address_2'   => '',
      'customer_city'        => '',
      'customer_state'       => '',
      'customer_postcode'    => '',
      'customer_country'     => (string) ($booking['bookingpress_customer_country'] ?? ($booking['customer_country'] ?? ($booking['country'] ?? ''))),

      // Ids for lookup
      '_booking_id'          => (int) ($booking['bookingpress_appointment_booking_id'] ?? $booking_id),
      '_appointment_id'      => (int) ($booking['bookingpress_appointment_id'] ?? ($booking['appointment_id'] ?? 0)),
      '_customer_id'         => (int) ($booking['bookingpress_customer_id'] ?? ($booking['customer_id'] ?? 0)),
      '_entry_id'            => (int) ($booking['bookingpress_entry_id'] ?? ($booking['entry_id'] ?? 0)),
    ];

    if ($debug) {
      MMBPL_Logger::log('Booking row keys: ' . implode(',', array_keys($booking)));
      MMBPL_Logger::log('Booking ids: booking_id=' . (int) $out['_booking_id'] . ' appointment_id=' . (int) $out['_appointment_id'] . ' customer_id=' . (int) $out['_customer_id'] . ' entry_id=' . (int) $out['_entry_id']);
    }

    return $out;
  }

  private static function get_appointment_meta_table() {
    global $wpdb;
    $t = $wpdb->get_var("SHOW TABLES LIKE '%bookingpress_appointment_meta%'");
    return $t ? $t : '';
  }

  private static function hydrate_address_from_appointment_meta(&$payload) {
    $settings = get_option(MMBPL_OPT, []);
    $debug = !empty($settings['debug']);

    $entry_id = (int) ($payload['_entry_id'] ?? 0);
    if ($entry_id <= 0) {
      if ($debug) MMBPL_Logger::log('Address hydrate: no entry_id on payload.');
      return;
    }

    $table = self::get_appointment_meta_table();
    if (!$table) {
      if ($debug) MMBPL_Logger::log('Address hydrate: bookingpress_appointment_meta table not found.');
      return;
    }

    $json = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
      "SELECT bookingpress_appointment_meta_value
       FROM {$table}
       WHERE bookingpress_entry_id=%d
         AND bookingpress_appointment_meta_key=%s
       LIMIT 1",
      $entry_id,
      'appointment_form_fields_data'
    ));

    if (!$json) {
      if ($debug) MMBPL_Logger::log('Address hydrate: no appointment_form_fields_data found for entry_id=' . $entry_id);
      return;
    }

    $data = json_decode((string) $json, true);
    if (!is_array($data)) {
      if ($debug) MMBPL_Logger::log('Address hydrate: appointment_form_fields_data was not valid JSON. entry_id=' . $entry_id);
      return;
    }

    $ff = $data['form_fields'] ?? [];
    if (!is_array($ff)) $ff = [];

    $addr1 = isset($ff[self::METAKEY_ADDRESS1]) ? trim((string) $ff[self::METAKEY_ADDRESS1]) : '';
    $post  = isset($ff[self::METAKEY_POSTCODE]) ? trim((string) $ff[self::METAKEY_POSTCODE]) : '';

    if ($addr1 !== '' && trim((string) ($payload['customer_address_1'] ?? '')) === '') {
      $payload['customer_address_1'] = $addr1;
    }
    if ($post !== '' && trim((string) ($payload['customer_postcode'] ?? '')) === '') {
      $payload['customer_postcode'] = $post;
    }

    if ($debug) {
      MMBPL_Logger::log('Address hydrate: entry_id=' . $entry_id . ' address_1=' . (string) ($payload['customer_address_1'] ?? '') . ' postcode=' . (string) ($payload['customer_postcode'] ?? ''));
    }
  }
}