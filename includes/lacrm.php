<?php
if (!defined('ABSPATH')) exit;

class MMBPL_LACRM {

  private static function api_key() {
    $settings = get_option(MMBPL_OPT, []);
    return trim((string) ($settings['lacrm_api_key'] ?? ''));
  }

  private static function debug_enabled() {
    $settings = get_option(MMBPL_OPT, []);
    return !empty($settings['debug']);
  }

  private static function call_api($function, $parameters = []) {
    $api_key = self::api_key();
    if (!$api_key) return false;

    $url = 'https://api.lessannoyingcrm.com/v2/';

    $body = [
      'Function'   => (string) $function,
      'Parameters' => (array) $parameters,
    ];

    $args = [
      'timeout' => 20,
      'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => $api_key,
      ],
      'body' => wp_json_encode($body),
    ];

    $resp = wp_remote_post($url, $args);

    if (is_wp_error($resp)) {
      MMBPL_Logger::log('LACRM HTTP error: ' . $resp->get_error_message());
      return false;
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = (string) wp_remote_retrieve_body($resp);

    $json = null;
    if ($raw !== '') $json = json_decode($raw, true);

    if (self::debug_enabled()) {
      MMBPL_Logger::log('LACRM call ' . $function . ' HTTP ' . $code . ' body=' . $raw);
    }

    if ($code === 400) {
      $err_code = is_array($json) ? ($json['ErrorCode'] ?? '') : '';
      $err_desc = is_array($json) ? ($json['ErrorDescription'] ?? '') : '';
      MMBPL_Logger::log('LACRM API error: ' . $err_code . ' ' . $err_desc);
      return false;
    }

    if ($code < 200 || $code >= 300) {
      MMBPL_Logger::log('LACRM unexpected HTTP ' . $code . ' body=' . $raw);
      return false;
    }

    if (!is_array($json)) return [];

    return $json;
  }

  private static function call_api_with_error_details($function, $parameters = []) {
    $api_key = self::api_key();
    if (!$api_key) return ['ok' => false, 'code' => 0, 'json' => null, 'raw' => ''];

    $url = 'https://api.lessannoyingcrm.com/v2/';

    $body = [
      'Function'   => (string) $function,
      'Parameters' => (array) $parameters,
    ];

    $args = [
      'timeout' => 20,
      'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => $api_key,
      ],
      'body' => wp_json_encode($body),
    ];

    $resp = wp_remote_post($url, $args);

    if (is_wp_error($resp)) {
      return ['ok' => false, 'code' => 0, 'json' => null, 'raw' => $resp->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = (string) wp_remote_retrieve_body($resp);

    $json = null;
    if ($raw !== '') $json = json_decode($raw, true);

    $ok = ($code >= 200 && $code < 300);

    return ['ok' => $ok, 'code' => $code, 'json' => $json, 'raw' => $raw];
  }

  public static function upsert_contact_by_email($bp) {
    $email = trim((string) ($bp['customer_email'] ?? ''));
    if (!$email) return false;

    $search = self::call_api('GetContacts', ['SearchTerms' => $email]);

    $contact_id = null;

    if (is_array($search) && !empty($search['Results']) && is_array($search['Results'])) {
      foreach ($search['Results'] as $c) {
        $existing = '';
        if (!empty($c['Email']) && is_array($c['Email']) && !empty($c['Email'][0])) {
          $existing = (string) ($c['Email'][0]['Text'] ?? $c['Email'][0]['Email'] ?? '');
        }
        if (strcasecmp($existing, $email) === 0) {
          $contact_id = (string) ($c['ContactId'] ?? '');
          break;
        }
      }
    }

    $name = trim((string) ($bp['customer_first_name'] ?? '') . ' ' . (string) ($bp['customer_last_name'] ?? ''));
    $phone = trim((string) ($bp['customer_phone'] ?? ''));

    // Address
    $street_parts = array_filter([
      trim((string) ($bp['customer_address_1'] ?? '')),
      trim((string) ($bp['customer_address_2'] ?? '')),
    ]);

    $street  = implode("\n", $street_parts);
    $city    = trim((string) ($bp['customer_city'] ?? ''));
    $state   = trim((string) ($bp['customer_state'] ?? ''));
    $zip     = trim((string) ($bp['customer_postcode'] ?? ''));
    $country = trim((string) ($bp['customer_country'] ?? ''));

    $has_address = ($street !== '' || $city !== '' || $state !== '' || $zip !== '' || $country !== '');

    if (self::debug_enabled()) {
      MMBPL_Logger::log('LACRM upsert_contact: email=' . $email . ' name=' . $name . ' phone=' . $phone . ' has_address=' . ($has_address ? '1' : '0'));
    }

    if ($contact_id) {
      $edit_params = [
        'ContactId' => $contact_id,
        'Email'     => [['Email' => $email, 'Type' => 'Work']],
      ];

      if ($name !== '') {
        $edit_params['Name'] = $name;
      }

      if ($phone !== '') {
        $edit_params['Phone'] = [['Phone' => $phone, 'Type' => 'Work']];
      }

      if ($has_address) {
        $edit_params['Address'] = [[
          'Street'  => $street,
          'City'    => $city,
          'State'   => $state,
          'Zip'     => $zip,
          'Country' => $country,
          'Type'    => 'Work',
        ]];
      }

      $ok = self::call_api('EditContact', $edit_params);
      if ($ok === false) return false;

      return $contact_id;
    }

    $user = self::call_api('GetUser', []);
    if (!$user || empty($user['UserId'])) {
      MMBPL_Logger::log('LACRM GetUser failed, cannot create contact.');
      return false;
    }

    $create_params = [
      'IsCompany'  => false,
      'AssignedTo' => (string) $user['UserId'],
      'Name'       => ($name !== '' ? $name : $email),
      'Email'      => [['Email' => $email, 'Type' => 'Work']],
    ];

    if ($phone !== '') {
      $create_params['Phone'] = [['Phone' => $phone, 'Type' => 'Work']];
    }

    if ($has_address) {
      $create_params['Address'] = [[
        'Street'  => $street,
        'City'    => $city,
        'State'   => $state,
        'Zip'     => $zip,
        'Country' => $country,
        'Type'    => 'Work',
      ]];
    }

    $create = self::call_api('CreateContact', $create_params);

    if (!$create || empty($create['ContactId'])) {
      MMBPL_Logger::log('LACRM CreateContact failed for email=' . $email);
      return false;
    }

    return (string) $create['ContactId'];
  }

  public static function create_event($event) {
    $contact_id = (string) ($event['ContactId'] ?? '');
    $name       = (string) ($event['Name'] ?? '');
    $start      = (string) ($event['StartDate'] ?? '');
    $end        = (string) ($event['EndDate'] ?? '');

    if ($contact_id === '' || $name === '' || $start === '' || $end === '') {
      MMBPL_Logger::log('CreateEvent missing required fields. ContactId/Name/StartDate/EndDate are required.');
      return false;
    }

    $resp = self::call_api('CreateEvent', [
      'Name'        => $name,
      'StartDate'   => $start,
      'EndDate'     => $end,
      'IsAllDay'    => !empty($event['IsAllDay']),
      'Location'    => (string) ($event['Location'] ?? ''),
      'Description' => (string) ($event['Description'] ?? ''),
      'Attendees'   => [[
        'IsUser'           => false,
        'AttendeeId'       => $contact_id,
        'AttendanceStatus' => 'IsAttending',
      ]],
    ]);

    if (!$resp || empty($resp['EventId'])) return false;

    return (string) $resp['EventId'];
  }

  public static function delete_event($event_id) {
    $event_id = (string) $event_id;
    if ($event_id === '') return false;

    $resp = self::call_api_with_error_details('DeleteEvent', ['EventId' => $event_id]);

    if (!empty($resp['ok'])) return true;

    $raw = strtolower((string) ($resp['raw'] ?? ''));
    $err_desc = '';
    $err_code = '';

    if (is_array($resp['json'])) {
      $err_desc = strtolower((string) ($resp['json']['ErrorDescription'] ?? ''));
      $err_code = strtolower((string) ($resp['json']['ErrorCode'] ?? ''));
    }

    $haystack = $raw . ' ' . $err_desc . ' ' . $err_code;

    if (
      strpos($haystack, 'not found') !== false ||
      strpos($haystack, 'does not exist') !== false ||
      strpos($haystack, 'no such') !== false
    ) {
      MMBPL_Logger::log('LACRM DeleteEvent: event already absent, treating as success. event_id=' . $event_id);
      return true;
    }

    MMBPL_Logger::log('LACRM DeleteEvent failed. event_id=' . $event_id . ' http=' . (int) ($resp['code'] ?? 0) . ' body=' . (string) ($resp['raw'] ?? ''));
    return false;
  }

  public static function create_note($note) {
    $contact_id = (string) ($note['ContactId'] ?? '');
    $text       = (string) ($note['Note'] ?? '');

    if ($contact_id === '' || $text === '') return false;

    $resp = self::call_api('CreateNote', [
      'ContactId' => $contact_id,
      'Note'      => $text,
    ]);

    return ($resp !== false);
  }
}