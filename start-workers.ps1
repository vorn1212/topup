# Opens 1 window for normal jobs and N windows for ABA payment QR sessions.
param([int]$Payway = 3)

$project = $PSScriptRoot

Start-Process powershell -ArgumentList '-NoExit', '-Command', "cd '$project'; php artisan queue:work --queue=default --tries=3 --sleep=1"

for ($i = 1; $i -le $Payway; $i++) {
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "cd '$project'; php artisan queue:work --queue=payway --tries=3 --sleep=1"
}
