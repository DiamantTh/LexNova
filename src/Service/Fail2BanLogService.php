<?php

declare(strict_types=1);

namespace LexNova\Service;

final readonly class Fail2BanLogService
{
    public const SETTING_KEY = 'security.fail2ban.enabled';

    public function __construct(
        private SystemSettingService $settings,
        private bool $configEnabled,
        private string $path,
    ) {
    }

    /** @return array{enabled: bool, source: 'database'|'config', config_enabled: bool, path: string, writable: bool} */
    public function status(): array
    {
        $setting = $this->settings->bool(self::SETTING_KEY, $this->configEnabled);

        return [
            'enabled' => $setting['value'],
            'source' => $setting['source'],
            'config_enabled' => $this->configEnabled,
            'path' => $this->path,
            'writable' => $this->isWritable(),
        ];
    }

    public function record(string $ip): void
    {
        try {
            if (!$this->settings->bool(self::SETTING_KEY, $this->configEnabled)['value']) {
                return;
            }

            $normalizedIp = filter_var($ip, FILTER_VALIDATE_IP);
            if (!is_string($normalizedIp)) {
                return;
            }

            $directory = dirname($this->path);
            if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                return;
            }

            $handle = @fopen($this->path, 'ab');
            if ($handle === false) {
                return;
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    return;
                }

                fwrite($handle, gmdate('Y-m-d\TH:i:s\Z') . " LEXNOVA_FAIL2BAN {$normalizedIp}\n");
                fflush($handle);
                @chmod($this->path, 0640);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable) {
            // An optional external signal must never make authentication fail.
        }
    }

    private function isWritable(): bool
    {
        if (is_file($this->path)) {
            return is_writable($this->path);
        }

        $directory = dirname($this->path);
        while (!is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }

        return is_writable($directory);
    }
}
