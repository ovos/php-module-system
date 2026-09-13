# Reporting a security issue

**Please do not open a public issue for a security problem.**

Report it privately, either way:

- **GitHub** — the *Security* tab → *Report a vulnerability*. This opens a
  private advisory only the maintainers can read.
- **E-mail** — [office@ovos.at](mailto:office@ovos.at). Put "php-library
  security" in the subject so it reaches the right desk quickly.

Tell us what you found, how to reproduce it, and which version or commit you
looked at. A proof of concept helps; it does not have to be polished.

## What happens next

- We confirm receipt, normally within a few working days.
- We tell you what we think it is and what we intend to do about it.
- When a fix ships we credit you by name, unless you would rather we did not.

## Scope

This module is the administrative surface of an application: a CLI and a small
set of HTTP endpoints. The interesting attack surface is anything reachable
over HTTP or run with elevated rights:

- the profiler endpoints (`application/controllers/Profiler.php`) — the access
  gate, and what a non-admin can see;
- the release and migration commands — anything that writes, runs SQL, or
  shells out;
- the encryption and http-auth helpers in the toolkit;
- session and log housekeeping — path handling, deletion scope.

Findings in the example configuration or the test fixtures are welcome too,
but they are usually documentation bugs rather than vulnerabilities.
