<?php

declare(strict_types=1);

/**
 * Smoke test of the example site on this working tree: php tests/smoke/run.php (CONTRIBUTING.md).
 *
 * Copies example/ to a temporary directory, lays the pages of tests/smoke/site over it, installs
 * the engine from this checkout (a Composer path repository), writes a production-like
 * config/config.php, runs vendor/bin/weft-health and sends requests through php -S. Contact
 * requests go by SMTP to a mail catcher with Mailpit's API (SMOKE_SMTP_PORT, default 1025, and
 * SMOKE_MAILPIT_URL, default http://127.0.0.1:8025), alerts to a fake AlertLoop. Needs composer
 * and Packagist. Exits with 0 when every check passes.
 */

$repo = dirname(__DIR__, 2);
$smtpPort = (int) (getenv('SMOKE_SMTP_PORT') ?: 1025);
$mailpit = rtrim((string) (getenv('SMOKE_MAILPIT_URL') ?: 'http://127.0.0.1:8025'), '/');
$site = sys_get_temp_dir() . '/weft-smoke-' . bin2hex(random_bytes(4));
$alerts = $site . '-alerts.jsonl';
$servers = [];
$failures = 0;

register_shutdown_function(static function () use (&$servers, $site, $alerts, $repo): void {
    chdir($repo);
    foreach ($servers as $server) {
        proc_terminate($server);
        proc_close($server);
    }
    removeTree($site);
    @unlink($alerts);
});

function check(bool $ok, string $what, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    $failures += $ok ? 0 : 1;
}

function stop(string $why): never
{
    fwrite(STDERR, "smoke: $why\n");
    exit(1);
}

/** Copy a directory, leaving out what a clone of the repository does not have. */
function copyTree(string $from, string $to): void
{
    mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..' || in_array($name, ['vendor', 'node_modules', 'composer.lock', 'config.php'], true)) {
            continue;
        }
        $path = $from . '/' . $name;
        if (is_dir($path)) {
            copyTree($path, $to . '/' . $name);
        } elseif (!preg_match('~/storage/(logs|cache)(/|$)~', strtr($from, '\\', '/')) || $name === '.gitkeep') {
            copy($path, $to . '/' . $name);
        }
    }
}

/** Remove a directory; a link out of it (vendor/golovanov/weft is the repository) is removed, not followed. */
function removeTree(string $dir): void
{
    $root = realpath($dir);
    if ($root === false) {
        return;
    }
    foreach (scandir($root) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $name;
        $real = realpath($path);
        if (is_link($path) || $real === false || $real !== $path) {
            @unlink($path) || @rmdir($path);
        } elseif (is_dir($path)) {
            removeTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($root);
}

/** The href of the canonical link, decoded. */
function canonical(string $html): string
{
    return preg_match('/<link rel="canonical" href="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : 'no canonical link';
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0') ?: stop('no free port');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
}

/** @param array<string, string> $env */
function serve(string $root, int $port, string $router, array $env = []): mixed
{
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $router],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes,
        null,
        $env === [] ? null : $env + getenv(),
    );
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port, $code, $error, 0.2)) {
            return $server;
        }
        usleep(100000);
    }
    stop("php -S on port $port did not start");
}

/**
 * @param array<string, string> $fields a form POST when not empty
 * @param list<string> $headers
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function request(string $url, array $fields = [], array $headers = []): array
{
    $curl = curl_init($url);
    $received = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $received[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($fields !== []) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($curl);
    if ($body === false) {
        stop("$url: " . curl_error($curl));
    }
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $received, 'body' => (string) $body];
}

// 0) The mail catcher answers with its SMTP greeting: a container just started may not yet, and
// a published port accepts connections before the server inside does.
$greeting = '';
for ($deadline = time() + 30; !str_starts_with($greeting, '220') && time() < $deadline; usleep(500000)) {
    $smtp = @fsockopen('127.0.0.1', $smtpPort, $errno, $error, 1);
    if ($smtp !== false) {
        stream_set_timeout($smtp, 2);
        $greeting = (string) fgets($smtp);
        fclose($smtp);
    }
}
str_starts_with($greeting, '220') || stop("no SMTP server answered on 127.0.0.1:{$smtpPort} within 30 s: start the mail catcher first (CONTRIBUTING.md, \"Smoke test of the example\")");

// 1) The site: example/ without its local files, the smoke pages over it, the engine from here.
copyTree($repo . '/example', $site);
copyTree(__DIR__ . '/site/pages', $site . '/pages-smoke');
foreach (scandir($site . '/pages-smoke') ?: [] as $name) {
    if ($name !== '.' && $name !== '..') {
        rename($site . '/pages-smoke/' . $name, $site . '/pages/' . $name);
    }
}
rmdir($site . '/pages-smoke');

$composer = json_decode((string) file_get_contents($site . '/composer.json'), true);
$composer['repositories'] = [['type' => 'path', 'url' => $repo]];
$composer['require']['golovanov/weft'] = '@dev';
file_put_contents($site . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chdir($site);
passthru((getenv('COMPOSER_BINARY') ?: 'composer') . ' update --no-interaction --no-progress --quiet', $code);
$code === 0 || stop('composer update failed');
realpath($site . '/vendor/golovanov/weft') === realpath($repo) || stop('the engine was not installed from the working tree');

// 2) Production-like settings: the site's address, an SMTP catcher, a fake AlertLoop, IndexNow.
$port = freePort();
$alertPort = freePort();
$base = 'http://127.0.0.1:' . $port;
$indexNowKey = bin2hex(random_bytes(16));
$appConfig = $site . '/app/Configs/app.php';
$replaced = str_replace(
    ["\$origin = 'https://example.com';", "// 'robots_groups' => [['agents' => ['ai-training'], 'disallow' => ['/']]],"],
    ["\$origin = '$base';", "'robots_groups' => [['agents' => ['ai-training'], 'disallow' => ['/']]],"],
    (string) file_get_contents($appConfig),
    $count,
);
$count === 2 || stop('app/Configs/app.php no longer has the lines the smoke test changes');
file_put_contents($appConfig, $replaced);

$constants = [
    'DEBUG' => false,
    'DEV_MODE' => false,
    'BASE_URI' => '',
    'LOGS_PATH' => $site . '/storage/logs',
    'APP_TIMEZONE' => 'UTC',
    'MAIL_DRIVER' => 'smtp',
    'MAIL_FROM' => 'noreply@smoke.test',
    'SMTP_HOST' => '127.0.0.1',
    'SMTP_PORT' => $smtpPort,
    'SMTP_ENCRYPTION' => 'none',
    'MAIL_TO' => 'owner@smoke.test',
    'ALERTLOOP_BASE_URL' => 'http://127.0.0.1:' . $alertPort,
    'ALERTLOOP_API_KEY' => 'smoke-key',
    'INDEXNOW_KEY' => $indexNowKey,
    'PROXY_IPS' => [],
];
$config = "<?php\n\ndeclare(strict_types=1);\n\n";
foreach ($constants as $name => $value) {
    $config .= "define('$name', " . var_export($value, true) . ");\n";
}
file_put_contents($site . '/config/config.php', $config);

// 3) Health of the production settings.
exec(escapeshellarg(PHP_BINARY) . ' vendor/bin/weft-health', $output, $code);
check($code === 0, 'weft-health exits with 0', implode("\n", $output));

// 4) Requests.
$servers[] = serve($site . '/public', $port, $site . '/public/index.php');
$servers[] = serve(__DIR__, $alertPort, __DIR__ . '/alertloop.php', ['SMOKE_ALERTS' => $alerts]);

$home = request($base . '/');
check($home['status'] === 200, 'GET / is 200', 'got ' . $home['status']);
$expected = [
    'x-content-type-options' => 'nosniff',
    'x-frame-options' => 'SAMEORIGIN',
    'referrer-policy' => 'strict-origin-when-cross-origin',
    'permissions-policy' => 'geolocation=(), microphone=(), camera=()',
];
check(array_intersect_key($home['headers'], $expected) == $expected, 'security headers are sent', json_encode($home['headers']) ?: '');
check(($home['headers']['x-trace-id'] ?? '') !== '', 'X-Trace-Id is sent');
check(!isset($home['headers']['strict-transport-security']), 'no HSTS while app.security.hsts is off');
check(canonical($home['body']) === $base . '/', 'canonical is absolute', canonical($home['body']));

$missing = request($base . '/no-such-page');
check($missing['status'] === 404, 'an unknown page is 404', 'got ' . $missing['status']);

// The contact form: CSRF token and session from the page, then the JSON post.
$page = request($base . '/contact');
preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $token);
preg_match('/^([^=]+=[^;]+)/', $page['headers']['set-cookie'] ?? '', $cookie);
check(isset($token[1], $cookie[1]), 'the contact page sets a CSRF token and a session cookie');
$marker = 'smoke-' . bin2hex(random_bytes(6));
$sent = request($base . '/api/contact', [
    '_csrf' => $token[1] ?? '',
    'name' => 'Smoke Test',
    'email' => 'visitor@smoke.test',
    'type' => 'question',
    'message' => 'Smoke test request ' . $marker,
    'website' => '',
], ['Accept: application/json', 'Cookie: ' . ($cookie[1] ?? '')]);
check($sent['status'] === 200 && (json_decode($sent['body'], true)['success'] ?? false) === true, 'the contact form answers 200 with success', $sent['status'] . ' ' . $sent['body']);
$found = [];
for ($i = 0; $i < 25 && $found === []; $i++) {
    $search = json_decode((string) @file_get_contents($mailpit . '/api/v1/search?query=' . $marker), true);
    $found = is_array($search) ? ($search['messages'] ?? []) : [];
    $found === [] && usleep(200000);
}
check(($found[0]['To'][0]['Address'] ?? '') === 'owner@smoke.test', 'the request reached MAIL_TO through SMTP', 'Mailpit at ' . $mailpit . ' has no message with ' . $marker);
$received = array_map(static fn(string $line): mixed => json_decode($line, true), @file($alerts, FILE_IGNORE_NEW_LINES) ?: []);
$alert = array_values(array_filter($received, static fn(mixed $a): bool => is_array($a) && str_contains((string) $a['body'], $marker)))[0] ?? [];
check(($alert['path'] ?? '') === '/v1/events' && ($alert['key'] ?? '') === 'smoke-key', 'the request alert reached AlertLoop through HttpClient', json_encode($received) ?: '');

$catchAll = request($base . '/smoke/a/b');
check($catchAll['status'] === 200 && str_contains($catchAll['body'], 'catch-all path=a/b'), 'catch-all page gets the rest of the path', 'got ' . $catchAll['status']);
$abort = request($base . '/smoke/missing');
check($abort['status'] === 404, 'catch-all page answers 404 with Abort::notFound()', 'got ' . $abort['status']);
check(!isset($abort['headers']['x-smoke-leak']) && isset($abort['headers']['x-content-type-options']), 'a header the page set before Abort is not sent, the engine ones are', json_encode($abort['headers']) ?: '');
$moved = request($base . '/smoke/moved');
check($moved['status'] === 301 && str_contains($moved['headers']['set-cookie'] ?? '', 'smoke_moved=1'), 'a cookie the page set before Abort::redirect() goes out with the redirect', json_encode($moved['headers']) ?: '');
$own = request($base . '/smoke/canonical');
check(canonical($own['body']) === $base . '/smoke/list', 'a canonical set with url() is absolute on page 1', canonical($own['body']));
check(str_contains($own['body'], '<meta property="og:url" content="' . $base . '/smoke/list"'), 'og:url from url() is absolute too');

$first = request($base . '/smoke/list?page=1');
check($first['status'] === 301 && str_ends_with($first['headers']['location'] ?? '', '/smoke/list'), '?page=1 redirects 301 to the list', $first['status'] . ' ' . ($first['headers']['location'] ?? ''));
$second = request($base . '/smoke/list?page=2');
check($second['status'] === 200 && str_contains($second['body'], 'list page 2 of 5'), '?page=2 is the second page', 'got ' . $second['status']);
check(canonical($second['body']) === $base . '/smoke/list?page=2', '?page=2 has its own absolute canonical', canonical($second['body']));
check(request($base . '/smoke/list?page=6')['status'] === 404, 'a page past the end is 404');
check(request($base . '/smoke/list?page=2%0A')['status'] === 404, 'a page number with a line break after it is 404');

$robots = request($base . '/robots.txt');
check(
    $robots['status'] === 200 && str_contains($robots['body'], 'Disallow: /api/') && str_contains($robots['body'], 'User-agent: GPTBot')
        && str_contains($robots['body'], 'Sitemap: ' . $base . '/sitemap.xml'),
    'robots.txt has the rules, the bot groups and the sitemap',
    $robots['body'],
);
$llms = request($base . '/llms.txt');
check($llms['status'] === 200 && str_starts_with($llms['body'], '# Weft'), 'llms.txt is served', $llms['status'] . ' ' . substr($llms['body'], 0, 80));
$key = request($base . '/' . $indexNowKey . '.txt');
check($key['status'] === 200 && trim($key['body']) === $indexNowKey, 'the IndexNow key file is served', 'got ' . $key['status']);

echo $failures === 0 ? "Smoke test passed.\n" : "Smoke test failed: $failures check(s).\n";
exit($failures === 0 ? 0 : 1);
