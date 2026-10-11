# StayCore PMS — Andaz Edition

## Project governance
Project/workspace: **Andaz Lifestyle Stay**, public brand **Andaz Vibe Stay - Arambol, Goa**. PMS is separate from the Andaz public website and Moksha wellness checkout. PHP plugin main branch declares **0.8.1**, requires WP >=6.4 and PHP >=8.0; CI uses PHP 8.2 and Node 20. Newer safeguard/extension draft PRs are NOT confirmed deployed.

**Booking safety is a P0 invariant:** no destructive migrations, silent booking overwrites or lost reservations. Encrypted per-save booking revisions, admin-audited restores, staging restore drill and independent offsite backup must be verified before rollout. All extensions must revalidate availability, totals and booking concurrency. Do not auto-upgrade WordPress/PHP/plugin without restore validation.

## Shared stack governance (11 October 2026)
This repository follows the **MOKSHA MASTER** technology stack/version policy as a shared engineering standard; it does NOT share business data, production credentials, legal identities or public branding across ventures.

Registry: https://docs.google.com/document/d/1-dSumLKkhmpzqGF2P-4x5_ZNUh9JamH3ApfXv4y4EeM/edit
Automation audit: https://docs.google.com/document/d/1ybmMCLX_9gy2SUAL9_h-8HpTWBVWt_ksbTShHn1FF5w/edit

**Mandatory on every dependency, platform, security or API change:**
1. Inspect actual source/lockfile and deployed versions; verify supported stable release and official security/advisory notes. Never assume source equals production.
2. Compare compatibility, licensing, API quotas, costs, deprecations and migration implications.
3. Record dated release deltas in the canonical registry and relevant project documentation, noting current/available/approved target version, evidence, tests and rollback.
4. For urgent security issues, prepare a minimal reviewed patch quickly. For routine updates, propose a tested PR. **Never silently deploy or auto-upgrade major versions, production services, database schemas, payments, booking data or customer messaging.**
5. Prefer shared reusable core with per-brand adapter/config; audit identity and tenant boundaries. Protect secrets and private data.
6. Avoid unnecessary GitHub Actions, long-running Git polling or automatic costly tests; prefer targeted CI and existing authenticated runtime.
7. Treat this file as guidance for Copilot/agents, not authorization to mutate production.
