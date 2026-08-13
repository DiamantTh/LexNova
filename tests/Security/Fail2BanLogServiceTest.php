<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\SystemSettingService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
$db->executeStatement(<<<'SQL'
CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL);

$cache = new Psr16Cache(new ArrayAdapter());
$settings = new SystemSettingService($db, $cache, 60);
$temporaryPath = sys_get_temp_dir() . '/lexnova-fail2ban-' . bin2hex(random_bytes(8)) . '.log';
$log = new Fail2BanLogService($settings, false, $temporaryPath);

$initial = $log->status();
if ($initial['enabled'] || $initial['source'] !== 'config') {
    throw new RuntimeException('Fail2ban config fallback is incorrect.');
}

$settings->setBool(Fail2BanLogService::SETTING_KEY, true);
$databaseOverride = $log->status();
if (!$databaseOverride['enabled'] || $databaseOverride['source'] !== 'database') {
    throw new RuntimeException('Database setting did not override the config fallback.');
}
if (!$databaseOverride['writable']) {
    throw new RuntimeException('Writable Fail2ban log location was not detected.');
}

$log->record('192.0.2.44');
$log->record("192.0.2.44\nFORGED");
$lines = file($temporaryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($lines) || count($lines) !== 1) {
    throw new RuntimeException('Fail2ban signal log accepted an invalid IP or lost an event.');
}

if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z LEXNOVA_FAIL2BAN 192\.0\.2\.44$/D', $lines[0]) !== 1) {
    throw new RuntimeException('Fail2ban signal log format is not stable.');
}

$settings->setBool(Fail2BanLogService::SETTING_KEY, false);
$log->record('192.0.2.45');
if (count((array) file($temporaryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) !== 1) {
    throw new RuntimeException('Database disable setting was ignored.');
}

$settings->setBool(Fail2BanLogService::SETTING_KEY, true);
$log->status();
$db->executeStatement('DROP TABLE system_settings');
if (!$log->status()['enabled']) {
    throw new RuntimeException('Cached database setting was not reused.');
}

unlink($temporaryPath);

echo "Fail2ban signal log security test: OK\n";
