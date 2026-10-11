<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Weft\Assets\Vite;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Mail\Mail;
use Golovanov\Weft\Security\Captcha;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Seo\LlmsTxt;

/**
 * The report of `vendor/bin/weft-health`: whether the site can run in production.
 *
 * `ok` is false when logs or rate-limit counters cannot be written, PROXY_IPS is unusable, the
 * captcha, a listed alert channel or the app.mail settings lack something, a static robots.txt or
 * llms.txt hides the generated one (`seo_errors`), `production` lists a setting for development
 * (unless the report is for development), or a check the site registered says so.
 */
final class Health
{
    /** @var array<string, callable(): array<string, mixed>> */
    private static array $checks = [];

    /**
     * Add a check of the site to the report, under `checks.<name>`. The callable returns the fields
     * to show and `ok`; anything but `ok => true`, or an exception, makes the report fail.
     *
     * @param callable(): array<string, mixed> $check
     */
    public static function check(string $name, callable $check): void
    {
        self::$checks[$name] = $check;
    }

    /**
     * @param bool $dev a development machine: `production` is listed but does not fail `ok`
     * @return array<string, mixed>
     */
    public static function report(bool $dev = false): array
    {
        $logsError = (string) Config::env('LOGS_PATH', '') === '' ? 'LOGS_PATH is not set' : ErrorHandler::logsError();
        $rateLimit = RateLimiter::storeStatus();

        $report = [
            'ok' => true,
            'php' => PHP_VERSION,
            'timezone' => date_default_timezone_get(),
            'config' => self::loadedConfig(),
            'debug' => ErrorHandler::debug(),
            'dev_mode' => (bool) Config::env('DEV_MODE', false),
            'production' => self::productionProblems(),
            'logs_writable' => $logsError === null,
            'logs_error' => $logsError,
            'cache_driver' => (string) Config::get('app.cache.driver', 'file'),
            'rate_limit_store' => $rateLimit['store'],
            'rate_limit_path' => $rateLimit['path'],
            'rate_limit_writable' => $rateLimit['writable'],
            'rate_limit_error' => $rateLimit['error'],
            'proxy_ips_errors' => Request::proxyIpsErrors(),
            'multilingual' => (bool) Config::get('app.locales.enabled', false),
            'cache_enabled' => (bool) Config::get('app.cache.enabled', false),
            'captcha_enabled' => (bool) Config::get('app.captcha.enabled', false),
            'captcha_missing_keys' => Captcha::missingKeys(),
            'alert_channels' => Alert::channels(),
            'alert_errors' => Alert::configErrors(),
            'mail_driver' => (string) Config::get('app.mail.driver', 'mail'),
            // Mail is in use when the site configures it; the mail alert channel checks it either way.
            'mail_errors' => Config::get('app.mail') === null ? [] : Mail::configErrors(),
            'seo_errors' => self::seoErrors(),
            'checks' => self::runChecks(),
        ];

        $report['ok'] = $report['logs_writable']
            && $report['rate_limit_writable']
            && !($report['cache_driver'] === 'redis' && $report['rate_limit_store'] === 'file')
            && $report['proxy_ips_errors'] === []
            && $report['captcha_missing_keys'] === []
            && $report['alert_errors'] === []
            && $report['mail_errors'] === []
            && $report['seo_errors'] === []
            && ($report['production'] === [] || $dev)
            && !in_array(false, array_column($report['checks'], 'ok'), true);

        return $report;
    }

    /** The environment config the site's bootstrap loaded, as a path under the site root. */
    private static function loadedConfig(): ?string
    {
        $dir = str_replace('\\', '/', Config::root()) . '/config/';
        foreach (get_included_files() as $file) {
            $file = str_replace('\\', '/', $file);
            if (str_starts_with($file, $dir) && !str_contains(substr($file, strlen($dir)), '/')) {
                return 'config/' . basename($file);
            }
        }
        return null;
    }

    /**
     * Settings that are fine on a development machine and wrong on a public site.
     *
     * @return list<string>
     */
    private static function productionProblems(): array
    {
        $problems = [];
        if (ErrorHandler::debug()) {
            $problems[] = 'DEBUG is on';
        }
        if ((bool) Config::env('DEV_MODE', false)) {
            $problems[] = 'DEV_MODE is on';
        }
        if (self::loadedConfig() === 'config/config.example.php') {
            $problems[] = 'config/config.example.php is loaded';
        }
        if (self::isExampleHost((string) parse_url((string) Config::get('app.url.base', ''), PHP_URL_HOST))) {
            $problems[] = 'app.url.base is on example.com';
        }
        $entry = Config::get('app.vite.entry');
        if (is_string($entry) && ($error = Vite::manifestError($entry)) !== null) {
            $problems[] = $error;
        }
        return $problems;
    }

    /**
     * robots and llms.txt settings that have no effect: a static file in the web root that the web
     * server serves instead of the generated one.
     *
     * @return list<string>
     */
    private static function seoErrors(): array
    {
        $errors = [];
        $root = rtrim((string) Config::env('WEB_ROOT', Config::root() . '/public'), '/\\');
        $set = [
            'robots.txt' => ['app.seo.robots_groups', (array) Config::get('app.seo.robots_groups', []) !== []],
            'llms.txt' => ['app.llms', LlmsTxt::enabled()],
        ];
        foreach ($set as $file => [$key, $configured]) {
            if ($configured && is_file("{$root}/{$file}")) {
                $errors[] = "{$root}/{$file} is served instead of {$key}";
            }
        }
        return $errors;
    }

    /** Whether $host is example.com or under it: the template's address, not a site's. */
    public static function isExampleHost(string $host): bool
    {
        $host = strtolower($host);
        return $host === 'example.com' || str_ends_with($host, '.example.com');
    }

    /** @return array<string, array<string, mixed>> */
    private static function runChecks(): array
    {
        $results = [];
        foreach (self::$checks as $name => $check) {
            try {
                $fields = $check();
            } catch (\Throwable $e) {
                $fields = ['ok' => false, 'error' => $e->getMessage()];
            }
            $results[$name] = ['ok' => ($fields['ok'] ?? null) === true] + $fields;
        }
        return $results;
    }
}
