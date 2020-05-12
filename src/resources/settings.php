<?php
$site_name = basename($site_path);

// Database settings.
$databases['default']['default'] = array (
  'database' => "${drupal.database.name}",
  'username' => 'root',
  'password' => '',
  'prefix' => '',
  'host' => 'mysql',
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
);

// Location of the site configuration files, relative to the site root.
$config_directories['sync'] = '../config/' . $site_name . '/sync';

// Files folders.
$settings['file_private_path'] = '/files/' . $site_name . '/files/private';
$settings['file_public_path'] = $site_path . '/files/public';
$settings['file_temp_path'] = '/files/' . $site_name . '/files/temp';
$conf['l10n_update_download_store'] = '/files/' . $site_name . '/files/translations';


// $settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') !== FALSE ? getenv('DRUPAL_HASH_SALT') : '0irtUmg80fCmL52y5x1Xj4N0JUBphTQmYFvnFwpEraZIu6_ZyQISb8EF9Fdyu3b8zIZR6kkNVg';

$settings['trusted_host_patterns'] = ['.*'];
