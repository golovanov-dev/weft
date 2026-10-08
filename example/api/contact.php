<?php

declare(strict_types=1);

/**
 * Contact endpoint (POST /api/contact).
 *
 * A worked example of a "sensitive spot" the way Weft intends it:
 *   honeypot → CSRF → rate limit → validation → reCAPTCHA v3 → service (alert + deliver).
 * The endpoint echoes JSON and sets the status code; the Kernel captures it and records the trace.
 */

use App\Services\ContactService;
use Golovanov\Weft\Http\Response;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Security\Captcha;
use Golovanov\Weft\Security\Csrf;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Support\Sanitize;

/** @var \Golovanov\Weft\Http\Request $request */

if (!$request->isPost()) {
    Response::json(['success' => false, 'error' => 'Method Not Allowed'], 405)->send();
    return;
}

$body = Sanitize::inputArray($request->post);

// 1) Honeypot — pretend success so bots don't learn anything.
if (RateLimiter::honeypot($body, 'website')) {
    Response::json(['success' => true, 'message' => 'OK'])->send();
    return;
}

// 2) CSRF (same-origin form).
if (!Csrf::check($body[Csrf::fieldName()] ?? null)) {
    Response::json(['success' => false, 'error' => 'Invalid session token'], 419)->send();
    return;
}

// 3) Rate limit (default baseline + a per-submit throttle).
if (!RateLimiter::allow('contact') || !RateLimiter::throttle('contact', 5)) {
    Response::json(['success' => false, 'error' => 'Too Many Requests'], 429)->send();
    return;
}

// 4) Validate.
$name = (string) ($body['name'] ?? '');
$email = (string) ($body['email'] ?? '');
$type = (string) ($body['type'] ?? '');
$message = (string) ($body['message'] ?? '');
$allowedTypes = (array) config('app.contact.request_types', []);

$errors = [];
if ($name === '') {
    $errors[] = 'name';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}
if ($type === '' || !in_array($type, $allowedTypes, true)) {
    $errors[] = 'type';
}
if (mb_strlen($message) < 10) {
    $errors[] = 'message';
}

if ($errors !== []) {
    Response::json(['success' => false, 'error' => 'Validation failed', 'fields' => $errors], 422)->send();
    return;
}

// 5) reCAPTCHA v3 (a no-op pass when captcha is disabled in config).
$captcha = Captcha::verify((string) ($body['recaptcha_token'] ?? ''), 'contact');
if (!$captcha['ok']) {
    // 'misconfigured' is a setup error, not a bot: the engine has already logged and alerted it.
    if (!in_array('misconfigured', $captcha['errorCodes'], true)) {
        Alert::send(
            'Contact form captcha rejected',
            Alert::WARNING,
            context: ['score' => $captcha['score']],
            category: 'security',
        );
    }
    Response::json(['success' => false, 'error' => 'Captcha verification failed'], 400)->send();
    return;
}

// 6) Hand off to the service (records + notifies + delivers).
$ok = (new ContactService())->submit([
    'name' => $name,
    'email' => $email,
    'type' => $type,
    'message' => $message,
    'project_url' => (string) ($body['project_url'] ?? ''),
]);

if (!$ok) {
    Response::json(['success' => false, 'error' => 'Could not send your message. Please email directly.'], 502)->send();
    return;
}

Response::json(['success' => true, 'message' => 'Thanks — I will get back to you.'])->send();
