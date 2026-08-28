# 360 AI

360 AI is the general AI layer for the 360 platform. It is **not** an SEO-only plugin. SEO and GEO/AEO will be modules alongside future content, auditing, internal-linking, image, Search Console, and analytics-assisted workflows.

## Current release: 0.1.0

The foundation release provides:

- a capability-protected **WordPress Admin → 360 AI** diagnostics dashboard;
- graceful dependency handling when 360 Platform Core is unavailable;
- normalized SiteContext, Clinic, Doctor, relationship, and location summaries through Platform Core;
- lightweight Page/Post counts and a bounded reusable content inventory;
- a structured context builder for future modules.

No AI provider, API key, generation, prompt, public endpoint, or Clinic/Doctor write exists in this release.

## Architecture

- `CoreAdapter` is the only 360 domain-data integration boundary and never reimplements Core legacy-meta interpretation.
- `SiteContextBuilder` creates provider-neutral structured context without presentation HTML.
- `ContentInventory` uses ordinary WordPress APIs for Pages and Posts and does not load post bodies.
- `AdminPage` owns capability checks, escaping, and dashboard presentation.

360 Platform Core must be active for 360 domain context. Pages and Posts remain available through standard WordPress APIs when Core is unavailable.

## Development

- PHP 7.4 or newer
- WordPress 6.0 or newer
- 360 Platform Core for connected integration tests

Run focused tests with:

```bash
php tests/run.php
```

Run `tests/local-integration.php` only against a local development WordPress installation.
