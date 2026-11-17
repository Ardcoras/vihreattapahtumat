<?php

/**
 * @file
 * Configure Drupal's bundled SMTP transport from runtime environment variables.
 */

// App Service settings are also available through getenv() when PHP does not
// populate $_ENV. Keep credentials out of exported Drupal configuration.
$smtp_environment = static function (string $name, mixed $default = NULL): mixed {
  $value = $_ENV[$name] ?? getenv($name);
  return $value === FALSE || $value === '' ? $default : $value;
};

if ($smtp_host = $smtp_environment('SMTP_HOST')) {
  $config['system.mail']['interface'] = ['default' => 'symfony_mailer'];
  $config['system.mail']['mailer_dsn'] = [
    'scheme' => 'smtp',
    'host' => $smtp_host,
    'port' => (int) $smtp_environment('SMTP_PORT', 587),
    'user' => $smtp_environment('SMTP_USER'),
    'password' => $smtp_environment('SMTP_PASSWORD'),
    'options' => [
      // Require STARTTLS before authenticating. Local mail catchers can opt out.
      'require_tls' => filter_var($smtp_environment('SMTP_REQUIRE_TLS', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],
  ];

  // Azure requires a MailFrom address from a verified, connected domain.
  if ($smtp_from = $smtp_environment('MAIL_FROM_ADDRESS')) {
    $config['system.site']['mail'] = $smtp_from;
  }
}

unset($smtp_environment, $smtp_host, $smtp_from);
