<?php
if (!defined('ABSPATH')) exit;

class MMBPL_LACRM {

  private static function api_key() {
    $settings = get_option(MMBPL_OPT, []);
    return $settings['lacrm_api_key'] ?? '';
  }

  private static function call($function, $params = []) {
    $key = self::api_key();
    if (!$key) return false;

    $body = [
      'APIToken' => $key,
      'Function' => $function,
      'Parameters' => $params
    ];

    $resp = wp_remote_post('https://api.lessannoyingcrm.com', [
      'headers' => ['Content-Type' => 'application/json'],
      'timeout' => 20,
      'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($resp)) {
      MMBPL_Logger::log('LACRM call error: ' . $resp->get_error_message());
      return false;
    }

    $json = json_decode(wp_remote_retrieve_body($resp), true);

    return $json;
  }

  public static function find_contact_by_email($email) {
    $r = self::call('GetContacts', ['SearchTerm' => (string) $email]);
    if (!$r || empty($r['Results']) || !is_array($r['Results'])) return false;

    foreach ($r['Results'] as $row) {
      if (!empty($row['Email']) && strtolower($row['Email']) === strtolower($email)) {
        return $row;
      }
    }

    return $r['Results'][0] ?? false;
  }

  public static function upsert_contact_by_email($bp) {
    $email = $bp['customer_email'] ?? '';
    if (!$email) return false;

    $found = self::find_contact_by_email($email);
    if ($found && !empty($found['ContactId'])) {
      // Optional update: if phone or name changes, update the record.
      $params = [
        'ContactId' => $found['ContactId'],
        'FirstName' => $bp['customer_first_name'] ?? '',
        'LastName'  => $bp['customer_last_name'] ?? '',
        'Email'     => $email,
        'Phone'     => $bp['customer_phone'] ?? '',
      ];
      self::call('EditContact', $params);
      return $found['ContactId'];
    }

    $r = self::call('CreateContact', [
      'FirstName' => $bp['customer_first_name'] ?? '',
      'LastName'  => $bp['customer_last_name'] ?? '',
      'Email'     => $email,
      'Phone'     => $bp['customer_phone'] ?? '',
    ]);

    return $r['ContactId'] ?? false;
  }

  public static function create_event($params) {
    // Expecting: ContactId, Subject, Date, Time, Details
    $r = self::call('CreateEvent', [
      'ContactId' => $params['ContactId'] ?? '',
      'Subject'   => $params['Subject'] ?? '',
      'Date'      => $params['Date'] ?? '',
      'Time'      => $params['Time'] ?? '',
      'Details'   => $params['Details'] ?? '',
    ]);

    return $r['EventId'] ?? ($r['event_id'] ?? false);
  }

  public static function delete_event($event_id) {
    if (!$event_id) return false;
    $r = self::call('DeleteEvent', ['EventId' => (string) $event_id]);

    // Some LACRM functions return true/false, some return an object.
    if ($r === true) return true;
    if (is_array($r) && (isset($r['Success']) || isset($r['success']))) return true;

    return (bool) $r;
  }

  public static function create_note($params) {
    $r = self::call('CreateNote', [
      'ContactId' => $params['ContactId'] ?? '',
      'Note'      => $params['Note'] ?? '',
    ]);

    return $r ? true : false;
  }
}