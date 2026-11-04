# Tradeflow login security

Every workspace route requires a signed-in user. Authentication uses Laravel's password hashing, CSRF protection and session-ID regeneration.

- Sessions expire after **25 minutes** without user activity.
- Every active session is invalidated at **12:00 AM Asia/Kolkata**.
- Browser activity is periodically confirmed with a CSRF-protected request, while the server independently enforces both limits.
- Login is limited to five failed attempts per minute per email/IP combination.
- Sessions are encrypted, HTTP-only and `SameSite=Strict`.

For a HTTPS deployment, keep `SESSION_SECURE_COOKIE=true` and set `APP_DEBUG=false`. Local HTTP development uses `SESSION_SECURE_COOKIE=false` only because a secure cookie cannot be sent over `http://127.0.0.1`.
