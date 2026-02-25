<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
  add_options_page(
    'BookingPress to LACRM',
    'BookingPress to LACRM',
    'manage_options',
    'mmbpl',
    'mmbpl_render_settings'
  );
});

add_action('admin_init', function () {
  register_setting('mmbpl', MMBPL_OPT);

  add_settings_section('mmbpl_main', '', function () {}, 'mmbpl');

  add_settings_field('lacrm_api_key', 'LACRM API key', function () {
    $s = get_option(MMBPL_OPT, []);
    printf(
      '<input type="text" name="%s[lacrm_api_key]" value="%s" class="regular-text" />',
      esc_attr(MMBPL_OPT),
      esc_attr($s['lacrm_api_key'] ?? '')
    );
  }, 'mmbpl', 'mmbpl_main');

  add_settings_field('event_title_template', 'Event title template', function () {
    $s = get_option(MMBPL_OPT, []);
    $val = $s['event_title_template'] ?? '{service} booking';
    echo '<input type="text" class="regular-text" name="' . esc_attr(MMBPL_OPT) . '[event_title_template]" value="' . esc_attr($val) . '" />';
    echo '<p class="description">Tokens: {service} {date} {time} {name} {email}</p>';
  }, 'mmbpl', 'mmbpl_main');

  add_settings_field('add_note', 'Add booking summary as note', function () {
    $s = get_option(MMBPL_OPT, []);
    $val = !empty($s['add_note']) ? 1 : 0;
    echo '<label><input type="checkbox" name="' . esc_attr(MMBPL_OPT) . '[add_note]" value="1" ' . checked(1, $val, false) . ' /> Enabled</label>';
  }, 'mmbpl', 'mmbpl_main');

  add_settings_field('delete_on_cancel', 'Delete LACRM event on cancel', function () {
    $s = get_option(MMBPL_OPT, []);
    $val = !empty($s['delete_on_cancel']) ? 1 : 0;
    echo '<label><input type="checkbox" name="' . esc_attr(MMBPL_OPT) . '[delete_on_cancel]" value="1" ' . checked(1, $val, false) . ' /> Enabled</label>';
  }, 'mmbpl', 'mmbpl_main');

  add_settings_field('debug', 'Debug logging', function () {
    $s = get_option(MMBPL_OPT, []);
    $val = !empty($s['debug']) ? 1 : 0;
    echo '<label><input type="checkbox" name="' . esc_attr(MMBPL_OPT) . '[debug]" value="1" ' . checked(1, $val, false) . ' /> Log table detection and payloads to error_log</label>';
  }, 'mmbpl', 'mmbpl_main');
});

function mmbpl_render_settings() {
  if (!current_user_can('manage_options')) return;

  echo '<div class="wrap">';
  echo '<h1>BookingPress to LACRM</h1>';
  echo '<form method="post" action="options.php">';
  settings_fields('mmbpl');
  do_settings_sections('mmbpl');
  submit_button('Save settings');
  echo '</form>';
  echo '<p>Logs go to your PHP error log. If WP_DEBUG_LOG is enabled, check wp-content/debug.log.</p>';
  echo '</div>';
}