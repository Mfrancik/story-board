# UI Inventory
Every reusable component. Consult BEFORE building any new UI (design-standards
§Reuse before build). Updated in the same story that creates/changes a component.

| Component | Type (Blade/Livewire/Filament) | Purpose | Used in |
|---|---|---|---|
| Logout | Livewire action | Starter kit: logs the user out (Fortify auth, unused by the board) | layouts/app sidebar user menu |
| Appearance | Livewire | Starter kit: light/dark/system appearance setting | settings/appearance |
| DeleteUserForm | Livewire | Starter kit: delete-account form with password confirm | settings/profile |
| Profile | Livewire | Starter kit: name/email profile form | settings/profile |
| Security | Livewire | Starter kit: password, passkeys and 2FA settings | settings/security |
| RecoveryCodes | Livewire | Starter kit: shows/regenerates 2FA recovery codes | settings/security |
| app-logo-icon | Blade | Starter kit: logo mark SVG | app-logo |
| app-logo | Blade | Starter kit: logo mark plus app name | layouts/app sidebar, auth layouts |
| auth-header | Blade | Starter kit: title + description heading on auth screens | auth pages |
| auth-session-status | Blade | Starter kit: flash status line on auth screens | auth pages |
| desktop-user-menu | Blade | Starter kit: user dropdown in the sidebar | layouts/app sidebar |
| passkey-registration | Blade | Starter kit: register-a-passkey control | settings/security |
| passkey-verify | Blade | Starter kit: sign in with a passkey | auth login |
| placeholder-pattern | Blade | Starter kit: striped SVG placeholder | dashboard |
| settings/layout | Blade | Starter kit: settings page shell with sub-nav | settings pages |
