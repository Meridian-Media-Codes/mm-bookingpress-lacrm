<?php
if (!defined('ABSPATH')) exit;

class MMBPL_BookingPress {

  // Your two custom fields
  const FIELD_ID_ADDRESS1 = 9;
  const FIELD_ID_POSTCODE = 10;

  // Meta keys shown in your BookingPress UI
  const METAKEY_ADDRESS1  = 'text_9Vv7N';
  const METAKEY_POSTCODE  = 'text_SlzYG';

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

    // Otherwise try mapping from appointment id columns back to booking PK
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

      // Address fields (we will hydrate these)
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

    self::hydrate_custom_address_from_anywhere($payload);

    return $payload;
  }

  private static function discover_tables($force = false) {
    global $wpdb;

    $like = '%' . $wpdb->esc_like('bookingpress') . '%';
    $raw = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));

    $tables = [
      'booking_table'  => '',
      'customer_table' => '',
      'service_table'  => '',
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

      // Defaults (these are hydrated later)
      'customer_address_1'   => '',
      'customer_address_2'   => '',
      'customer_city'        => '',
      'customer_state'       => '',
      'customer_postcode'    => '',
      'customer_country'     => (string) ($booking['bookingpress_customer_country'] ?? ($booking['customer_country'] ?? ($booking['country'] ?? ''))),

      // Ids for meta lookup
      '_booking_id'          => (int) ($booking['bookingpress_appointment_booking_id'] ?? $booking_id),
      '_appointment_id'      => (int) ($booking['bookingpress_appointment_id'] ?? ($booking['appointment_id'] ?? 0)),
      '_customer_id'         => (int) ($booking['bookingpress_customer_id'] ?? ($booking['customer_id'] ?? 0)),
      '_entry_id'            => (int) ($booking['bookingpress_entry_id'] ?? ($booking['entry_id'] ?? 0)),
    ];

    if ($debug) {
      MMBPL_Logger::log('Booking row keys: ' . implode(',', array_keys($booking)));
      MMBPL_Logger::log('Booking ids: booking_id=' . (int) $out['_booking_id'] . ' appointment_id=' . (int) $out['_appointment_id'] . ' customer_id=' . (int) $out['_customer_id'] . ' entry_id=' . (int) $out['_entry_id']);
    }

    // Scan blob columns too
    self::hydrate_from_row_blobs($out, $booking);

    return $out;
  }

  private static function hydrate_custom_address_from_anywhere(&$payload) {
    $needs_addr = (trim((string) ($payload['customer_address_1'] ?? '')) === '');
    $needs_post = (trim((string) ($payload['customer_postcode'] ?? '')) === '');

    if (!$needs_addr && !$needs_post) return;

    // Your DB shows appointment_meta has bookingpress_entry_id and bookingpress_appointment_id
    self::hydrate_from_meta_table(
      $payload,
      'bookingpress_appointment_meta',
      'bookingpress_entry_id',
      (int) ($payload['_entry_id'] ?? 0)
    );

    self::hydrate_from_meta_table(
      $payload,
      'bookingpress_appointment_meta',
      'bookingpress_appointment_id',
      (int) ($payload['_appointment_id'] ?? 0)
    );

    // Customers meta table exists but does not store address in your screenshots, still safe to scan
    self::hydrate_from_meta_table(
      $payload,
      'bookingpress_customers_meta',
      'bookingpress_customer_id',
      (int) ($payload['_customer_id'] ?? 0)
    );

    $settings = get_option(MMBPL_OPT, []);
    if (!empty($settings['debug'])) {
      MMBPL_Logger::log(
        'After hydrate address_1=' . (string) ($payload['customer_address_1'] ?? '') .
        ' postcode=' . (string) ($payload['customer_postcode'] ?? '') .
        ' country=' . (string) ($payload['customer_country'] ?? '')
      );
    }
  }

  private static function hydrate_from_meta_table(&$payload, $table_suffix, $owner_col, $owner_id) {
    global $wpdb;

    $owner_id = (int) $owner_id;
    if ($owner_id <= 0) return;

    $table = $wpdb->prefix . $table_suffix;
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) return;

    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    if (!$cols) return;
    $colset = array_flip($cols);

    if (!isset($colset[$owner_col])) return;

    $key_col = null;
    $val_col = null;

    foreach ([
      'bookingpress_entry_meta_key',
      'bookingpress_appointment_meta_key',
      'bookingpress_customersmeta_key',
      'meta_key',
      'bookingpress_meta_key'
    ] as $c) {
      if (isset($colset[$c])) { $key_col = $c; break; }
    }

    foreach ([
      'bookingpress_entry_meta_value',
      'bookingpress_appointment_meta_value',
      'bookingpress_customersmeta_value',
      'meta_value',
      'bookingpress_meta_value'
    ] as $c) {
      if (isset($colset[$c])) { $val_col = $c; break; }
    }

    if (!$key_col || !$val_col) return;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT {$key_col} AS k, {$val_col} AS v
       FROM {$table}
       WHERE {$owner_col} = %d",
      $owner_id
    ), ARRAY_A);

    if (!$rows) return;

    $kv = [];
    foreach ($rows as $r) {
      $k = isset($r['k']) ? (string) $r['k'] : '';
      if ($k === '') continue;
      $kv[$k] = isset($r['v']) ? (string) $r['v'] : '';
    }

    self::apply_custom_field_candidates($payload, $kv);

    // Scan any blobs
    foreach ($kv as $v) {
      if (!is_string($v) || $v === '') continue;
      self::apply_from_blob_string($payload, $v);
    }
  }

  private static function apply_custom_field_candidates(&$payload, array $kv) {
    $addr_candidates = self::candidate_keys(self::METAKEY_ADDRESS1, self::FIELD_ID_ADDRESS1);
    $post_candidates = self::candidate_keys(self::METAKEY_POSTCODE, self::FIELD_ID_POSTCODE);

    if (trim((string) ($payload['customer_address_1'] ?? '')) === '') {
      foreach ($addr_candidates as $k) {
        if (isset($kv[$k]) && trim((string) $kv[$k]) !== '') {
          $payload['customer_address_1'] = trim((string) $kv[$k]);
          break;
        }
      }
    }

    if (trim((string) ($payload['customer_postcode'] ?? '')) === '') {
      foreach ($post_candidates as $k) {
        if (isset($kv[$k]) && trim((string) $kv[$k]) !== '') {
          $payload['customer_postcode'] = trim((string) $kv[$k]);
          break;
        }
      }
    }
  }

  private static function hydrate_from_row_blobs(&$out, array $row) {
    foreach ($row as $v) {
      if (!is_string($v) || $v === '') continue;
      self::apply_from_blob_string($out, $v);
    }
  }

  private static function apply_from_blob_string(&$payload, $blob) {
    $blob = (string) $blob;

    // JSON attempt
    $trim = ltrim($blob);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
      $json = json_decode($blob, true);
      if (is_array($json)) {

        // BookingPress stores custom form fields inside something like:
        // {"form_fields":{...}} or {"form_fields":{"text_XXXXX":"value"}}
        $form_fields = null;

        if (isset($json['form_fields']) && is_array($json['form_fields'])) {
          $form_fields = $json['form_fields'];
        } elseif (isset($json['form_fields_data']['form_fields']) && is_array($json['form_fields_data']['form_fields'])) {
          $form_fields = $json['form_fields_data']['form_fields'];
        } elseif (isset($json['appointment_form_fields_data']['form_fields']) && is_array($json['appointment_form_fields_data']['form_fields'])) {
          $form_fields = $json['appointment_form_fields_data']['form_fields'];
        }

        if (is_array($form_fields)) {
          // Address
          if (trim((string) ($payload['customer_address_1'] ?? '')) === '') {
            $addr = self::find_value_case_insensitive($form_fields, self::candidate_keys(self::METAKEY_ADDRESS1, self::FIELD_ID_ADDRESS1));
            if ($addr !== '') $payload['customer_address_1'] = $addr;
          }

          // Postcode
          if (trim((string) ($payload['customer_postcode'] ?? '')) === '') {
            $post = self::find_value_case_insensitive($form_fields, self::candidate_keys(self::METAKEY_POSTCODE, self::FIELD_ID_POSTCODE));
            if ($post === '') {
              $post = self::find_postcode_fallback($form_fields);
            }
            if ($post !== '') $payload['customer_postcode'] = $post;
          }
        }
      }
    }

    // Regex fallback for non JSON blobs
    if (trim((string) ($payload['customer_address_1'] ?? '')) === '') {
      $val = self::extract_value_from_blob($blob, self::METAKEY_ADDRESS1);
      if ($val === '') $val = self::extract_value_from_blob($blob, (string) self::FIELD_ID_ADDRESS1);
      if ($val !== '') $payload['customer_address_1'] = $val;
    }

    if (trim((string) ($payload['customer_postcode'] ?? '')) === '') {
      $val = self::extract_value_from_blob($blob, self::METAKEY_POSTCODE);
      if ($val === '') $val = self::extract_value_from_blob($blob, (string) self::FIELD_ID_POSTCODE);
      if ($val === '') {
        // Last ditch, scan blob for a postcode shaped value
        $val = self::extract_uk_postcode_from_text($blob);
      }
      if ($val !== '') $payload['customer_postcode'] = $val;
    }
  }

  private static function candidate_keys($meta_key, $field_id) {
    $meta_key = (string) $meta_key;
    $field_id = (int) $field_id;
    $id = (string) $field_id;

    return array_values(array_unique([
      $meta_key,
      $id,
      'field_' . $meta_key,
      'field_' . $id,
      'form_field_' . $id,
      'bookingpress_' . $meta_key,
      'bookingpress_' . $id,
      'custom_' . $meta_key,
      'custom_' . $id,
    ]));
  }

  private static function find_value_case_insensitive(array $data, array $candidates) {
    // direct key match
    foreach ($candidates as $k) {
      if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') {
        return trim((string) $data[$k]);
      }
    }

    // case insensitive match
    $lower_map = [];
    foreach ($data as $k => $v) {
      $lower_map[strtolower((string) $k)] = $v;
    }

    foreach ($candidates as $k) {
      $lk = strtolower((string) $k);
      if (isset($lower_map[$lk]) && is_string($lower_map[$lk]) && trim($lower_map[$lk]) !== '') {
        return trim((string) $lower_map[$lk]);
      }
    }

    return '';
  }

  private static function find_postcode_fallback(array $form_fields) {
    // First pass: keys that look postcode-ish
    foreach ($form_fields as $k => $v) {
      if (!is_string($v)) continue;
      $val = trim($v);
      if ($val === '') continue;

      $kk = strtolower((string) $k);
      if (strpos($kk, 'post') !== false || strpos($kk, 'zip') !== false) {
        $pc = self::extract_uk_postcode_from_text($val);
        if ($pc !== '') return $pc;
      }
    }

    // Second pass: any value that looks like a UK postcode
    foreach ($form_fields as $v) {
      if (!is_string($v)) continue;
      $pc = self::extract_uk_postcode_from_text($v);
      if ($pc !== '') return $pc;
    }

    return '';
  }

  private static function extract_uk_postcode_from_text($text) {
    $text = strtoupper(trim((string) $text));
    if ($text === '') return '';

    // Simple UK postcode matcher
    if (preg_match('/\b([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2})\b/', $text, $m)) {
      // normalize single space
      $pc = preg_replace('/\s+/', '', $m[1]);
      return substr($pc, 0, -3) . ' ' . substr($pc, -3);
    }

    return '';
  }

  private static function extract_value_from_blob($blob, $key) {
    $blob = (string) $blob;
    $key  = (string) $key;
    if ($blob === '' || $key === '') return '';

    $quoted = preg_quote($key, '/');

    if (preg_match('/"' . $quoted . '"\s*:\s*"([^"]*)"/', $blob, $m)) {
      return trim(stripslashes($m[1]));
    }

    if (preg_match('/' . $quoted . '\s*=\s*([^\s&"]+)/', $blob, $m)) {
      return trim((string) $m[1]);
    }

    return '';
  }
}