# Contributing

Thanks for helping improve RentManager-PHP.

## Before opening a change

1. Search existing issues and pull requests.
2. Open an issue first for large API or behavior changes.
3. Never include live credentials, tokens, tenant data, exports, or proprietary customer information.

## Development checks

Use PHP 8.2 or newer and run:

```bash
php tests/lint.php
php tests/run.php
```

Add or update mock-transport tests for behavior changes. Keep the SDK dependency-free unless a dependency has been discussed and approved first.

## Pull requests

Describe the problem, the approach, user-visible effects, and validation performed. Keep changes focused. By submitting a contribution, you confirm that you have the right to provide it and agree that it may be distributed under this project's PolyForm Noncommercial License 1.0.0.
