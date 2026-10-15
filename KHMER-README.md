# Khmer language for the customer side

## Install
1. Unzip into the project root, say yes to overwrite (layouts, navigation, public-layout, the customer views, routes/web.php, SecurityAccessTest.php).
2. `php artisan migrate`   (adds users.locale)
3. `composer dump-autoload`
4. `php artisan optimize:clear`
5. `php artisan test`

## How it works
- EN | ខ្មែរ switcher in the top menu, on the login pages and on the public pages.
- Saved in the session, a cookie, and (signed in) on the account. English stays the default.
- All text uses `__('English sentence')`; Khmer is in `lang/km.json`. To add a language later: add it to `SetLocale::LOCALES` and create `lang/xx.json`.
- The admin area stays English.

## Please have a native speaker review
Payment, wallet, refund and "order failed" sentences in `lang/km.json`.
