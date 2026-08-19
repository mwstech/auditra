# Security Policy

Auditra exposes information about a WordPress site over an authenticated HTTP
endpoint. That is a security-relevant surface, and reports about it are welcome.

## Supported versions

| Version | Supported |
| ------- | --------- |
| 1.0.x   | ✅ |
| < 1.0   | ❌ — pre-release, never published |

Fixes land in a new patch release on the wordpress.org directory. There is no
backporting to older lines; the current release is the supported one.

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Use GitHub's private vulnerability reporting:
[**Report a vulnerability**](https://github.com/mwstech/auditra/security/advisories/new).
It is private between you and the maintainers, needs no email exchange, and
gives us a place to work on a fix and credit you when it ships.

If you cannot use GitHub, report it through the wordpress.org plugin team at
`plugins@wordpress.org`, who will route it to us.

### What to expect

This is maintained by a small team, so these are commitments we can actually
keep rather than aspirational ones:

- **Acknowledgement within 5 working days.**
- An assessment — whether we can reproduce it, and the severity we think it
  carries — **within 10 working days**.
- For a confirmed issue, a patch release as soon as it is ready, and a note in
  the changelog. We will credit you by the name you ask for, or not at all if
  you prefer.
- If we disagree that something is a vulnerability, we will say so plainly and
  explain why, rather than going quiet.

## Scope

**In scope** — anything that lets someone read data they should not, or make
the plugin do something it says it cannot:

- Bypassing token authentication on `/wp-json/auditra/v1/mcp/{token}`
- Any write, state change, or file operation performed through the endpoint.
  The plugin claims to be structurally read-only and CI fails the build if a
  write function appears; a counter-example is a serious finding.
- Data exposed beyond what the readme documents — post content, user data,
  credentials, salts, or option *values* rather than names and sizes
- Injection of any kind: SQL, header, log, or response
- Privilege escalation on the settings screen, or CSRF against its actions
- Denial of service reachable **before** authentication

**Out of scope:**

- Sites where an administrator enabled the endpoint and shared the connection
  URL. The URL contains the token and the readme says to treat it like a
  password.
- The behaviour of the AI client at the other end. The plugin returns facts; it
  does not control what a model does with them.
- Vulnerabilities in WordPress core, other plugins, or the two upstream APIs
  (wordpress.org, WPVulnerability) — report those to their maintainers.
- Missing hardening headers, version disclosure, or anything a scanner reports
  without a concrete exploit path.
- Denial of service that requires a valid token.

## Design commitments

These are the properties the plugin is built to hold. If you can break one, it
is a finding worth reporting even if it does not fit the categories above:

- The endpoint is **disabled on install** and answers 404 until an administrator
  enables it and generates a token.
- The plugin performs **no write operation of any kind** — no plugin
  management, no database writes, no file writes.
- Tokens are 32 bytes from `random_bytes`, hex encoded, compared with
  `hash_equals`, stored in a non-autoloaded option, and never logged.
- **No telemetry**, with or without consent.
- A check that could not run reports that it could not run, rather than
  returning an empty result that reads as "clean".

The reasoning behind each is recorded in [docs/DECISIONS.md](docs/DECISIONS.md).
