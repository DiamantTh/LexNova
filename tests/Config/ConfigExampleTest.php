<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$instance = toml_decode((string) file_get_contents($root . '/config/config.example.toml'), asArray: true);
$security = toml_decode((string) file_get_contents($root . '/config/security.toml'), asArray: true);

if (!is_array($instance) || !is_array($security)) {
    throw new RuntimeException('Example configuration files must decode to arrays.');
}

/** @var array<string, string> $expectedTypes */
$expectedTypes = [
    'app.base_url' => 'string',
    'app.locale' => 'string',
    'security.totp_app_key' => 'string',
    'security.password.algo' => 'string',
    'security.password.options.memory_cost' => 'integer',
    'security.password.options.time_cost' => 'integer',
    'security.password.options.threads' => 'integer',
    'security.password_policy.min_length' => 'integer',
    'security.password_policy.max_length' => 'integer',
    'security.password_policy.ascii_only' => 'boolean',
    'security.password_policy.min_score' => 'integer',
    'security.password_policy.hibp.enabled' => 'boolean',
    'security.password_policy.hibp.min_count' => 'integer',
    'security.password_policy.hibp.timeout_ms' => 'integer',
    'security.password_policy.hibp.fail_open' => 'boolean',
    'security.password_policy.hibp.endpoint' => 'string',
    'security.email_subject.format' => 'string',
    'security.email_subject.date_format' => 'string',
    'security.email_subject.strip_www' => 'boolean',
    'security.email_subject.custom_pattern' => 'string',
    'security.generator.diceware.word_count' => 'integer',
    'security.generator.diceware.separator' => 'string',
    'security.generator.random.length' => 'integer',
    'security.generator.random.require_upper' => 'boolean',
    'security.generator.random.require_digits' => 'boolean',
    'security.generator.random.require_symbols' => 'boolean',
    'security.totp.digits' => 'integer',
    'security.totp.algorithm' => 'string',
    'security.totp.period' => 'integer',
    'security.totp.window' => 'integer',
    'security.rate_limit.max_attempts' => 'integer',
    'security.rate_limit.block_seconds' => 'integer',
    'security.fail2ban.enabled' => 'boolean',
    'security.fail2ban.path' => 'string',
    'security.fail2ban.settings_cache_ttl' => 'integer',
    'db.dsn' => 'string',
    'db.user' => 'string',
    'db.password' => 'string',
    'install.lock' => 'string',
    'install.password_file' => 'string',
    'install.config_file' => 'string',
    'session.name' => 'string',
    'session.secure' => 'boolean',
    'session.httponly' => 'boolean',
    'session.samesite' => 'string',
    'session.cookie_lifetime' => 'integer',
    'session.cookie_path' => 'string',
    'session.cookie_domain' => 'string',
    'cache.adapter' => 'string',
    'cache.dsn' => 'string',
    'cache.namespace' => 'string',
    'cache.default_ttl' => 'integer',
    'log.path' => 'string',
    'log.level' => 'string',
    'twig.cache' => 'boolean',
];

foreach ($expectedTypes as $path => $expectedType) {
    $value = $instance;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            throw new RuntimeException("Missing configuration key: {$path}");
        }
        $value = $value[$segment];
    }

    if (gettype($value) !== $expectedType) {
        throw new RuntimeException("Configuration key {$path} must be {$expectedType}, got " . gettype($value));
    }
}

$assertSecurityDefaultsAreDocumented = function (
    array $defaults,
    array $documented,
    string $path = 'security',
) use (&$assertSecurityDefaultsAreDocumented): void {
    foreach ($defaults as $key => $defaultValue) {
        $currentPath = $path . '.' . $key;
        if (!array_key_exists($key, $documented)) {
            throw new RuntimeException("Security default is missing from config.example.toml: {$currentPath}");
        }

        $documentedValue = $documented[$key];
        if (is_array($defaultValue)) {
            if (!is_array($documentedValue)) {
                throw new RuntimeException("Configuration section {$currentPath} must be a table.");
            }
            $assertSecurityDefaultsAreDocumented($defaultValue, $documentedValue, $currentPath);

            continue;
        }

        if (gettype($documentedValue) !== gettype($defaultValue)) {
            throw new RuntimeException("Configuration key {$currentPath} does not match its default type.");
        }
    }
};

$assertSecurityDefaultsAreDocumented($security, (array) $instance['security']);

if ($instance['log']['level'] !== 'warning') {
    throw new RuntimeException('Example log level must match the installer/runtime default.');
}

if ($instance['security']['fail2ban']['path'] !== 'var/log/fail2ban.log') {
    throw new RuntimeException('Fail2ban example path must remain project-relative.');
}

echo "Configuration example contract test: OK\n";
