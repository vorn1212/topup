# Site settings: website name, logo, icon, colours, game cards

Admin > **Settings** (super admin only). Changes are audited.

## Install (PowerShell, in C:\xampp\htdocs\topup)

1. Copy the files from the zip into the project (same folders).
2. Register the provider (run once; safe to repeat):

```powershell
$f='bootstrap\providers.php'; $t=Get-Content $f -Raw
if ($t -notmatch 'SiteSettingsServiceProvider') { $t = $t -replace 'App\\Providers\\AppServiceProvider::class,', "App\Providers\AppServiceProvider::class,`n    App\Providers\SiteSettingsServiceProvider::class,"; Set-Content $f $t }
Select-String $f -Pattern 'SiteSettingsServiceProvider'
```
The last line must print one match. If it prints nothing, add `App\Providers\SiteSettingsServiceProvider::class,` to the list by hand.

3. Run:

```powershell
php artisan migrate
php artisan storage:link
composer dump-autoload
php artisan optimize:clear
php artisan test
```

4. Open http://127.0.0.1:8000/admin/settings as the super admin.

## Notes
- The default name is APP_NAME from .env. A name saved here wins.
- Queue workers (and e-mails they send) keep the old name until you close and reopen the worker windows.
- Only real PNG/JPG/WebP (logo) and PNG/ICO (favicon) are accepted. SVG is refused because it can carry scripts.
- Colours are stored as #RRGGBB and checked again on every page, so a bad database value can never inject code.
- Text colour (black/white) is chosen automatically for contrast.
- Admin pages share the page background and button colour; the game detail and top-up pages keep their white panels.
