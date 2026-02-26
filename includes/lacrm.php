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
      'Function'    => (string) $function,
      'Parameters'  => (array) $parameters,
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
    if ($raw !== '') {
      $json = json_decode($raw, true);
    }

    if (self::debug_enabled()) {
      MMBPL_Logger::log('LACRM call ' . $function . ' HTTP ' . $code . ' body=' . $raw);
    }

    // LACRM v2 returns HTTP 400 for errors with ErrorCode / ErrorDescription in body
    if ($code === 400) {
      $err_code = is_array($json) ? ($json['ErrorCode'] ?? '') : '';
      $err_desc = is_array($json) ? ($json['ErrorDescription'] ?? '') : '';
      MMBPL_Logger::log('LACRM API error: ' . $err_code . ' ' . $err_desc);
      return false;
    }

    // Other non-200 codes
    if ($code < 200 || $code >= 300) {
      MMBPL_Logger::log('LACRM unexpected HTTP ' . $code . ' body=' . $raw);
      return false;
    }

    if (!is_array($json)) {
      // Some calls return empty response on success, but most return JSON objects.
      return [];
    }

    return $json;
  }

  public static function upsert_contact_by_email($bp) {
    $email = trim((string) ($bp['customer_email'] ?? ''));
    if (!$email) return false;

    // Find existing contact via GetContacts SearchTerms (email)
    $search = self::call_api('GetContacts', [
      'SearchTerms' => $email
    ]);

    $contact_id = null;

    if (is_array($search) && !empty($search['Results']) && is_array($search['Results'])) {
      foreach ($search['Results'] as $c) {
        // API returns Email as an array; the first item usually holds the primary email as Text
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

    $name = trim(
      (string) ($bp['customer_first_name'] ?? '') . ' ' . (string) ($bp['customer_last_name'] ?? '')
    );

    $phone = trim((string) ($bp['customer_phone'] ?? ''));

    if ($contact_id) {
      // EditContact: only pass fields you want to update
      $edit_params = [
        'ContactId' => $contact_id,
      ];

      if ($name) {
        $edit_params['Name'] = $name;
      }

      // Email and Phone are arrays, like CreateContact uses
      $edit_params['Email'] = [
        ['Email' => $email, 'Type' => 'Work']
      ];

      if ($phone) {
        $edit_params['Phone'] = [
          ['Phone' => $phone, 'Type' => 'Work']
        ];
      }

      $ok = self::call_api('EditContact', $edit_params);
      if ($ok === false) return false;

      return $contact_id;
    }

    // CreateContact needs AssignedTo. Get your user first.
    $user = self::call_api('GetUser', []);
    if (!$user || empty($user['UserId'])) {
      MMBPL_Logger::log('LACRM GetUser failed, cannot create contact.');
      return false;
    }

    $create = self::call_api('CreateContact', [
      'IsCompany'   => false,
      'AssignedTo'  => (string) $user['UserId'],
      'Name'        => ($name ?: $email),
      'Email'       => [
        ['Email' => $email, 'Type' => 'Work']
      ],
      'Phone'       => ($phone ? [['Phone' => $phone, 'Type' => 'Work']] : []),
    ]);

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

    if (!$contact_id || !$name || !$start || !$end) {
      MMBPL_Logger::log('CreateEvent missing required fields. ContactId/Name/StartDate/EndDate are required.');
      return false;
    }

    // CreateEvent supports Attendees list. To link a contact, add them as an attendee with IsUser=false.
    $resp = self::call_api('CreateEvent', [
      'Name'        => $name,
      'StartDate'   => $start,
      'EndDate'     => $end,
      'IsAllDay'    => !empty($event['IsAllDay']),
      'Location'    => (string) ($event['Location'] ?? ''),
      'Description' => (string) ($event['Description'] ?? ''),
      'Attendees'   => [
        [
          'IsUser'           => false,
          'AttendeeId'       => $contact_id,
          'AttendanceStatus' => 'IsAttending',
        ]
      ],
    ]);

    if (!$resp || empty($resp['EventId'])) return false;

    return (string) $resp['EventId'];
  }

  public static function delete_event($event_id) {
    $event_id = (string) $event_id;
    if (!$event_id) return false;

    $resp = self::call_api('DeleteEvent', [
      'EventId' => $event_id
    ]);

    // DeleteEvent returns nothing on success, so any non-false is ok.
    return ($resp !== false);
  }

  public static function create_note($note) {
    $contact_id = (string) ($note['ContactId'] ?? '');
    $text       = (string) ($note['Note'] ?? '');

    if (!$contact_id || $text === '') return false;

    $resp = self::call_api('CreateNote', [
      'ContactId' => $contact_id,
      'Note'      => $text,
    ]);

    return ($resp !== false);
  }
}