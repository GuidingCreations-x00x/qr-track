# Changelog — Tracking LaB (qr-track)

> A running log of work sessions, decisions, and findings.
> Newest entries at the top, oldest at the bottom.

---

## 2026-06-29
- **built:** `generator.php` — standalone QR Code Generator with live preview via qr-code-styling JS library. Real-time controls for dot style, corner style, body color, corner color, background color, and logo upload. Download PNG and save-to-DB for authenticated users. Two-column layout, dark theme, responsive
- **built:** Auto-fill from query params (`?client=URL&title=NAME`) when linking from analytics page
- **deployed:** generator.php + config.php migration (dot_style, corner_style columns) to Hostinger, pushed to GitHub
- **removed (ui):** GD-based design options from add/edit modals (checkboxes and color pickers with no live preview) — replaced by JS live preview in generator.php
- **updated:** "+ New QR Code" buttons in header.php and client_analytics.php link to generator.php with client/context params
- **fixed:** Mobile responsive CSS across header.php, index.php, client_analytics.php — header stacks vertically, cards go single column, chart capped at 250px, pills shrink, modals don't overflow on phones
- **fixed:** Login password — updated ADMIN_PASS in config.php to Connect@4045
- **built:** `client_analytics.php` — 30-day Chart.js bar chart, per-client routing via `?client=` param, top placements ranking, device/OS breakdown (collapsible), geographic section with ip-api.com lookup, scam warning badge, PWA manifest.json for installability
- **built:** QR management tools added to client_analytics.php (add/edit/delete/toggle/get code modals) scoped per client
- **redesigned:** `index.php` dashboard — flat QR list replaced with per-URL group cards with scan bars, search/filter bar, "View Analytics →" links per client
- **added:** Scam protection to `index.php` redirect handler — skips logging scans from same IP+UA hitting >10/hr
- **updated:** Device OS detection confirmed working — Android, iOS, Windows, Mac, Linux all detected correctly
- **decided:** Generator page as standalone tool → allows public "free QR code generator" for ad monetization, independent from QR router
- **decided:** Per-client analytics dashboard as single page with query params vs separate pages — fraction-of-second reload acceptable
- **discovered:** chillerlan/php-qrcode library already supports circular modules, per-part colors, and custom output classes — just needed UI exposure
- **discovered:** qr-code-styling (kozakdenys) JS library provides real-time canvas preview with dot styles, corner styles, gradients — better UX than server-side GD rendering
- **researched:** QR analytics dashboard patterns from Flowcode, Uniqode/Beaconstac, QRCodeChimp, QRCodeKIT, Bitly — informed client_analytics.php layout
- **installed:** Chart.js (CDN), qr-code-styling (CDN)
- **next:** Logo transparency support for uploaded PNGs, gradient option for QR body, different corner eye/finder patterns

---
