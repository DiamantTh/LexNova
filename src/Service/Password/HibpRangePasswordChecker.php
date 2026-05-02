<?php

declare(strict_types=1);

namespace LexNova\Service\Password;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * HaveIBeenPwned "Pwned Passwords" Range-API client.
 *
 * Uses k-anonymity: only the first 5 chars of the SHA-1 hash are sent.
 * The full password (and its full hash) NEVER leaves the server.
 *
 * Endpoint:  https://api.pwnedpasswords.com/range/{first5sha1}
 * Docs:      https://haveibeenpwned.com/API/v3#PwnedPasswords
 *
 * Behaviour on transient HTTP/network errors is governed by `failOpen`:
 *   true  → return 0 (do not block the user) and log a warning
 *   false → return 1 (treat as breached) so the policy rejects the password
 *
 * Hash prefixes are cached for {@see self::CACHE_TTL} seconds via PSR-16
 * to minimise network round-trips and protect rate limits.
 */
final readonly class HibpRangePasswordChecker implements BreachedPasswordCheckerInterface
{
    public const string DEFAULT_ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    private const string USER_AGENT = 'LexNova-HIBP-Check/1.0';
    private const int CACHE_TTL = 86400; // 24 h
    private const string CACHE_PREFIX = 'hibp.range.';

    public function __construct(
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private bool $failOpen = true,
        private int $timeoutMs = 1500,
        private string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    public function timesSeen(string $password): int
    {
        if ($password === '') {
            return 0;
        }

        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $body = $this->fetchRange($prefix);
        } catch (\RuntimeException $e) {
            $this->logger->warning('HIBP range lookup failed', ['error' => $e->getMessage()]);

            // fail-closed: pretend the password is breached so the policy rejects it.
            return $this->failOpen ? 0 : 1;
        }

        // Each line: "<35-char-suffix>:<count>"
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$lineSuffix, $count] = explode(':', $line, 2);
            if (hash_equals($suffix, strtoupper(trim($lineSuffix)))) {
                $n = (int) trim($count);

                // Padding rows are returned with count 0 — ignore those.
                return $n > 0 ? $n : 0;
            }
        }

        return 0;
    }

    private function fetchRange(string $prefix): string
    {
        $cacheKey = self::CACHE_PREFIX . $prefix;
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $url = $this->endpoint . $prefix;

        // Defence-in-depth: refuse anything that is not plain HTTPS so a
        // misconfigured endpoint cannot trigger requests via file://, php://,
        // ftp:// or other stream wrappers.
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('HIBP endpoint must use https://.');
        }

        $timeoutSeconds = max(0.1, $this->timeoutMs / 1000);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\n"
                          . "Accept: text/plain\r\n"
                          . "Add-Padding: true\r\n",
                'timeout' => $timeoutSeconds,
                'follow_location' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException('HIBP request failed: ' . $err);
        }

        // $http_response_header is auto-populated by the HTTP stream wrapper
        // in the local scope after a successful file_get_contents() call.
        /** @var list<string> $headers */
        $headers = $http_response_header ?? []; // @phpstan-ignore nullCoalesce.variable
        $status = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($status !== 200) {
            throw new \RuntimeException("HIBP returned HTTP {$status}.");
        }

        $this->cache->set($cacheKey, $body, self::CACHE_TTL);

        return $body;
    }
}
