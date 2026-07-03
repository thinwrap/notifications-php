# Contributing

Thanks for considering a contribution to `thinwrap/notifications`. This is a thin,
stateless wrapper over notification providers — contributions that keep the facade
small and faithful to the underlying APIs are the most welcome.

## Getting started

```bash
composer install
```

## Build, test, and lint

The project ships Composer scripts for everything you need:

```bash
composer test                  # run the PHPUnit suite
composer analyse               # PHPStan static analysis
composer cs-check              # check code style (php-cs-fixer, dry-run)
composer cs-fix                # auto-fix code style
composer validate-frontmatter  # validate per-connector README frontmatter (35 providers)
```

Run `composer test`, `composer analyse`, and `composer cs-check` before opening a
pull request. CI runs the same gates across PHP 8.2 / 8.3 / 8.4.

## Pull request conventions

- Branch from the default branch; keep each PR focused on a single change.
- Include tests for behavior changes; update the relevant per-connector
  `src/Providers/<Provider>/README.md` when you touch a connector's surface.
- Use clear, present-tense commit messages.
- Ensure all quality gates pass locally before requesting review.

## Design guardrails

This package is a **stateless wrapper**: no caching, retries, or mutable global
state. The normalized facade exposes only features supported by ≥90% of providers;
everything else flows through the `_passthrough` escape hatch. See
[`.ai/guidelines.md`](.ai/guidelines.md), [`.ai/ARCHITECTURE.md`](.ai/ARCHITECTURE.md),
and [`.ai/CONVENTIONS.md`](.ai/CONVENTIONS.md) before proposing structural changes.

## Reporting bugs and security issues

- Bugs and feature requests: <https://github.com/thinwrap/notifications-php/issues>
- Security vulnerabilities: see [`SECURITY.md`](SECURITY.md) — please report
  privately, not via public issues.
