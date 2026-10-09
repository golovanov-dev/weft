<?php

declare(strict_types=1);

/**
 * Contact endpoint (POST /api/contact).
 *
 * A worked example of a "sensitive spot" the way Weft intends it:
 *   honeypot → CSRF → validation → rate limit → reCAPTCHA v3 → service (alert + email).
 * The form's script asks for JSON (Accept: application/json) and gets it with the status code; a
 * form posted without JavaScript is redirected (303) back to the contact page with ?status=…, and
 * the page shows the message. The Kernel captures the output and records the trace.
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

/**
 * Answer the form: JSON for the script, a redirect to the contact page for a plain form post.
 *
 * @param string $status what the page shows without JavaScript: sent, invalid, expired or error
 * @param list<array{name: string, text: string}> $errors the fields to correct (422)
 */
$reply = static function (int $code, string $status, string $text, array $errors = []) use ($request): void {
    if (!$request->wantsJson()) {
        $query = ['status' => $status];
        if ($errors !== []) {
            $query['fields'] = implode(',', array_column($errors, 'name'));
        }
        Response::redirect(url('/contact') . '?' . http_build_query($query), 303)->send();
        return;
    }
    $data = $code < 300 ? ['success' => true, 'message' => $text] : ['success' => false, 'error' => $text];
    if ($errors !== []) {
        $data['errors'] = $errors;
    }
    Response::json($data, $code)->send();
};

$body = Sanitize::inputArray($request->post);

// 1) Honeypot — pretend success so bots don't learn anything.
if (RateLimiter::honeypot($body, 'website')) {
    $reply(200, 'sent', 'Thanks — I will get back to you.');
    return;
}

// 2) CSRF (same-origin form). A field that is not a string is an invalid token.
if (!Csrf::check($body[Csrf::fieldName()] ?? null)) {
    $reply(419, 'expired', 'Your session has expired. Please refresh the page and send the form again.');
    return;
}

// 3) Validate. Before the rate limit: correcting a field and sending again is not throttled.
$field = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
$name = $field('name');
$email = $field('email');
$type = $field('type');
$message = $field('message');
$allowedTypes = (array) config('app.contact.request_types', []);

$errors = [];
if ($name === '') {
    $errors[] = ['name' => 'name', 'text' => 'Please enter your name.'];
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = ['name' => 'email', 'text' => 'Please enter a valid email address.'];
}
if ($type === '' || !in_array($type, $allowedTypes, true)) {
    $errors[] = ['name' => 'type', 'text' => 'Please choose a request type.'];
}
if (mb_strlen($message) < 10) {
    $errors[] = ['name' => 'message', 'text' => 'Please write at least 10 characters.'];
}

if ($errors !== []) {
    $reply(422, 'invalid', 'Please correct the highlighted fields.', $errors);
    return;
}

// 4) Rate limit (default baseline + a per-submit throttle).
if (!RateLimiter::allow('contact') || !RateLimiter::throttle('contact', 5)) {
    $reply(429, 'error', 'Too many messages. Please wait a minute and try again.');
    return;
}

// 5) reCAPTCHA v3 (a no-op pass when captcha is disabled in config).
$captcha = Captcha::verify($field('recaptcha_token'), 'contact');
if (!$captcha['ok']) {
    // 'misconfigured' is a setup error, not a bot: the engine has already logged and alerted it.
    // One alert per five minutes per dedupe key: a bot run would otherwise alert on every attempt.
    // A rejection the setup or Google causes (the reasons Captcha::verify() logs) has a key of its
    // own, so a bot's alert does not hold it back, and the other way round.
    $setupFault = match (true) {
        in_array('request-failed', $captcha['errorCodes'], true) => 'request-failed',
        in_array('invalid-input-secret', $captcha['errorCodes'], true) => 'invalid-input-secret',
        $captcha['success'] && !$captcha['hostOk'] => 'host',
        $captcha['success'] && !$captcha['actionOk'] => 'action',
        default => null,
    };
    if (!in_array('misconfigured', $captcha['errorCodes'], true)) {
        Alert::send(
            'Contact form captcha rejected',
            Alert::WARNING,
            dedupeKey: $setupFault === null ? 'contact:captcha-rejected' : 'contact:captcha-failed:' . $setupFault,
            context: [
                'score' => $captcha['score'],
                'errorCodes' => $captcha['errorCodes'],
                'hostOk' => $captcha['hostOk'],
                'actionOk' => $captcha['actionOk'],
            ],
            category: 'security',
        );
    }
    $reply(400, 'error', 'Captcha verification failed. Please try again.');
    return;
}

// 6) Hand off to the service (records + notifies + delivers).
$delivered = (new ContactService())->submit([
    'name' => $name,
    'email' => $email,
    'type' => $type,
    'message' => $message,
    'project_url' => $field('project_url'),
]);

// Success only when AlertLoop or mail() confirmed it; the service logged the failure.
if (!$delivered) {
    $reply(503, 'error', 'Your message could not be sent right now. Please try again later.');
    return;
}

$reply(200, 'sent', 'Thanks — I will get back to you.');
