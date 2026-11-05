> Historical branding validation record. The subsequent [shared POS session update](shared-pos-sessions.md) removes the inactivity lock and its screen. Counts below describe the earlier branding validation.

# Nightshift authentication branding cleanup

The existing canonical mark is `public/nightshift.svg`: the dark rounded tile with the gold cocktail glass. Authentication, registration, settings and the main workspace now reuse that same asset. No new logo was designed.

## User-facing cleanup

- Shared `app-logo-icon` / `app-logo` components now use Nightshift. A shared `nightshift-brand` component supplies the mark and wordmark.
- Simple, card and split auth layouts share a compact Nightshift container. Login, staff registration, forgot/reset password, password confirmation, email verification and two-factor/recovery-code views inherit it.
- Login now says **Log in to Nightshift**, keeps the username and masked four-digit PIN fields, and retains both the admin-reset helper and business-onboarding link.
- Business registration reuses the same identity. Its forms and actions were preserved.
- Settings desktop/mobile layouts no longer link to the Laravel starter-kit repository or documentation.
- The unused welcome view no longer contains starter-kit promotional text or Laravel SVGs.
- Normal HTTP error pages use an application-owned Nightshift layout, preserving their status codes.
- The main sidebar uses the same canonical SVG asset rather than a separate inline rendering of the mark.
- The application display-name fallback is Nightshift. Environment files, session/cache identifiers and authentication configuration were not changed.

## Language and appearance

English login copy matches the requested title/subtitle/button/helper. Kiswahili uses **Ingia Nightshift**, **Jina la Mtumiaji**, **PIN ya tarakimu 4**, **Ingia**, **Umesahau PIN? Wasiliana na msimamizi wa biashara yako.**, and **Huna akaunti? Sajili biashara yako**.

Poppins remains locally bundled. Auth inputs are 16px, buttons are 15px with strong weight, the mark is 36px, and the layout supports desktop, 390px mobile and 320px narrow mobile widths. Light is the existing guest default. The small appearance toggle applies the existing client appearance function to the current page; it does not introduce a new session or account-preference mechanism. Existing signed-in theme behavior is preserved.

## Browser and installed-app icons

- `public/favicon.svg` is an exact copy of the existing canonical SVG.
- `public/favicon.ico` contains 16px, 32px and 48px exports of that same mark.
- `public/apple-touch-icon.png` is 180×180.
- The manifest's previously missing `/icons/icon-192.png` and `/icons/icon-512.png` now exist, exported from the same SVG.
- Manifest name/short name remain Nightshift, with the existing start URL and standalone display behavior. The theme color matches the application's gold.
- Shared icon links include a Nightshift version query to refresh old starter-kit icons. Guest, auth, settings and workspace heads include the manifest and Apple icon metadata.

The raster exports were rendered from the existing SVG and resized without changing the design. Laravel framework code, packages and legitimate PHP namespace references remain intact. Development-only framework exception diagnostics remain framework-owned; ordinary production HTTP error screens use Nightshift branding.

## Files changed for branding

- `config/app.php`: display-name fallback.
- `resources/views/components/app-logo-icon.blade.php`, `app-logo.blade.php`, `nightshift-brand.blade.php`: shared identity.
- `resources/views/layouts/auth/simple.blade.php`, `card.blade.php`, `split.blade.php`: common auth presentation.
- `resources/views/layouts/app/header.blade.php`, `sidebar.blade.php`: remove starter-kit external links.
- `resources/views/pages/auth/login.blade.php`: requested login title.
- `resources/views/business/register.blade.php`: shared mark and toolbar.
- `resources/views/partials/head.blade.php`, `brand-icons.blade.php`, `auth-toolbar.blade.php`: titles, icon metadata and guest controls.
- `resources/views/layouts/nightshift.blade.php`, `bar.blade.php`: shared browser-icon metadata.
- `resources/views/welcome.blade.php`, `errors/minimal.blade.php`: Nightshift welcome/error identity.
- `resources/css/app.css`, `resources/js/nightshift.js`: auth spacing/type/theme styles and canonical sidebar image.
- `lang/sw.json`, `lang/sw/ui.php`: requested Kiswahili wording.
- `public/favicon.svg`, `favicon.ico`, `apple-touch-icon.png`, `manifest.webmanifest`, `icons/icon-192.png`, `icons/icon-512.png`: browser/PWA branding.
- `tests/Feature/AuthBrandingTest.php`, `LanguagePreferenceTest.php`: brand/asset/error/status/localization checks and updated title expectations.
- `scripts/browser-auth-branding-smoke.mjs`, `browser-language-smoke.mjs`: browser coverage and updated title expectations.
- `docs/auth-branding-cleanup.md`: implementation and validation record.

## Validation

- Full Laravel suite: **243 tests / 2,603 assertions passed**.
- Focused authentication/localization/branding suite: **36 tests / 447 assertions passed**.
- PHPStan: **0 errors**.
- PHP/JavaScript/CSS formatting and production frontend build: **passed**.
- Browser matrix: **179 checks passed**, including admin/owner, manager, cashier, waiter and store keeper login; EN/SW; light/dark; desktop/mobile; business registration; password recovery/reset; profile settings; 404; and favicon/manifest asset availability.
- Screenshots were visually inspected for desktop English light/dark and mobile Kiswahili dark. No Laravel logo or starter-kit link was visible, no horizontal overflow was detected, and no browser exception or HTTP 5xx response occurred.

A before/after SHA-256 audit confirmed all 127 protected application/authentication/accounting/routing/session/theme files were unchanged. The login and business-registration form markup matched the original forms exactly; only surrounding presentation and the login heading changed. Existing tenant/role/session and business regression tests passed.

Browser evidence: [results](/tmp/nightshift-auth-branding-browser/result.json), [desktop light login](/tmp/nightshift-auth-branding-browser/1440-en-light-login.png), [desktop dark login](/tmp/nightshift-auth-branding-browser/1440-en-dark-login.png), [mobile Kiswahili dark login](/tmp/nightshift-auth-branding-browser/390-sw-dark-login.png), [320px login](/tmp/nightshift-auth-branding-browser/320-sw-dark-login.png).

No production deployment was performed. No remaining Laravel-branded screen was found in normal user-facing authentication, registration, settings, recovery or error flows.
