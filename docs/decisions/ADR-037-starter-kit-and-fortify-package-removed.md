# ADR-037 — The auth starter kit and the `laravel/fortify` package are removed

Date: 2026-10-11 · Status: accepted · Story: RD-1

## Context

The board is a localhost, single-owner tool with no auth. The Laravel starter kit still shipped about 2,000
lines of auth, settings, passkey and two-factor code, plus the `/dashboard`, `/settings/*`, `/login` and
`/register` routes. None of it was used. RD-1's first criterion is that those URLs return 404.

## Decision

Delete the starter kit's routes, Livewire components, actions, views, layouts, provider and tests, and
`config/fortify.php` (the owner approved touching `config/` on 2026-10-10). Then remove the `laravel/fortify`
composer package and its transitive dependencies (passkeys, webauthn, google2fa, QR code and others). This was
done only after a grep showed nothing else referenced them.

Alternatives rejected:
- **Keep the package installed with the app's provider and routes removed.** The package's own service
  provider still registers `/login`, `/register` and the rest, so the 404 criterion could not pass.
- **Keep the package and turn off every Fortify feature in `config/fortify.php`.** This leaves a configured but
  unused auth stack and dependency surface in place, to switch off forever.

## Consequences

- `composer.lock` loses those packages and nothing else. The large diff is line shifts only.
- `User` keeps `HasFactory, Notifiable`. The `users` / `passkeys` tables and `two_factor_*` columns stay, because
  dropping them is destructive and was not asked for.
- SB-6 (hosted board) will need auth chosen afresh, rather than revived from this kit.
- Some leftovers are still unused: `resources/js/passkeys.js` and its Vite input, the `@laravel/passkeys`
  npm dependency, and `UserFactory::withTwoFactor()`. Removing them is the owner's call.
