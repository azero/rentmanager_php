# Security policy

## Reporting a vulnerability

Do not disclose suspected vulnerabilities in a public issue. Use GitHub's private vulnerability reporting at:

https://github.com/azero/rentmanager_php/security/advisories/new

Include the affected version or commit, reproduction steps, impact, and any suggested mitigation. You should receive an initial response within seven days.

## Credential and data safety

- Never commit Rent Manager credentials, API tokens, private keys, tenant exports, or live smoke-test output.
- Keep `.env`, `.rentmanager-token.json`, and service-ticket export directories local.
- Use a least-privilege Rent Manager API account and rotate credentials that may have been exposed.
- Treat API error details and exported records as potentially sensitive tenant or business data.
- If sensitive data reaches Git history, remove it from history and rotate the affected credential. A later deletion commit is not sufficient.

The examples read credentials from environment variables or a local `.env` file. `.env.example` must contain placeholders only.
