# Security Policy

Weft sits in front of every request a site serves and handles its forms. A vulnerability in it is
a vulnerability in every site built on it — so please report one, and please report it privately
first.

## Supported versions

| Version | Supported |
|---|---|
| 0.2.x | ✅ current |

Before 1.0.0 only the latest minor line is supported. A fix ships as a patch release of that line
and is not backported: upgrading within 0.x is the fix.

## Reporting a vulnerability

**Do not open a public issue.** Use GitHub's
**[Report a vulnerability](../../security/advisories/new)** button on this repository. It opens a
private advisory that only the maintainers can see, and the fix and the published advisory come
out of the same thread.

Please include:

- what you did, in enough detail to reproduce it;
- the Weft version (`composer show golovanov/weft`) and PHP version;
- what an attacker gains;
- anything you already know about a fix.

You will get an acknowledgement within **3 working days** and an assessment within **10**. If a
report turns out to be a real vulnerability, we will agree a disclosure date with you, credit you
in the advisory and the changelog unless you would rather stay anonymous, and ship the fix in a
patch release of the supported line.

If you do not hear back within the acknowledgement window, please chase it — a missed email is far
more likely than a decision to ignore you.

## Scope

**In scope:** the engine in `src/` — routing and path handling, request parsing, CSRF, rate
limiting, captcha verification, security headers, JWT verification, request tracing and its
redaction, the database wrapper, and the alert client.

**Out of scope**, because they are the site's job:

- Code a site writes on top of Weft: its pages, API endpoints and services.
- A site's deployment: the web server, TLS, the document root, file permissions and secrets in
  `config/config.php`.
- The `example/` site as a deployed application — it is documentation, not a hardened product.
  Engine defects it demonstrates are in scope.
- Findings from an automated scanner with no demonstrated impact.
