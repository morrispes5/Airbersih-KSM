param([ValidateSet('local','e2e','operational')][string]$Environment = 'operational')
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskWeb = Join-Path $taskRoot 'web'
$taskPort = if ($Environment -eq 'e2e') { 8086 } else { 8085 }
$taskRuntime = Join-Path $taskRoot 'private\runtime'
New-Item -ItemType Directory -Path $taskRuntime -Force | Out-Null
foreach ($taskDirectory in @('storage\app\private','storage\framework\cache\data','storage\framework\sessions','storage\framework\views','storage\logs','bootstrap\cache')) { New-Item -ItemType Directory -Path (Join-Path $taskWeb $taskDirectory) -Force | Out-Null }
$taskStateFile = Join-Path $taskRuntime "$Environment.json"
if (Test-Path -LiteralPath $taskStateFile) { & (Join-Path $PSScriptRoot 'stop-app.ps1') -Environment $Environment }
if (Get-NetTCPConnection -State Listen -LocalPort $taskPort -ErrorAction SilentlyContinue) { throw "Port $taskPort sudah digunakan. Tidak ada proses lain yang dihentikan." }
if (!(Test-Path -LiteralPath (Join-Path $taskWeb 'vendor\autoload.php'))) { throw 'Dependensi belum tersedia. Jalankan composer install pada folder web.' }
if (!(Test-Path -LiteralPath (Join-Path $taskWeb 'public\build\manifest.json'))) { Push-Location $taskWeb; try { & npm.cmd ci; if ($LASTEXITCODE) { throw 'npm ci gagal.' }; & npm.cmd run build; if ($LASTEXITCODE) { throw 'Build gagal.' } } finally { Pop-Location } }
$taskOldEnv = $env:APP_ENV
try {
    $env:APP_ENV = $Environment
    Push-Location $taskWeb
    try { & php artisan air:check; if ($LASTEXITCODE) { throw 'Pemeriksaan target Aiven gagal.' } } finally { Pop-Location }
    $taskRouter = Join-Path $taskWeb 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
    $taskPhp = (Get-Command php.exe).Source
    $taskServer = Start-Process -FilePath $taskPhp -ArgumentList @('-d','upload_max_filesize=60M','-d','post_max_size=64M','-d','memory_limit=256M','-S',"127.0.0.1:$taskPort",('"'+$taskRouter+'"')) -WorkingDirectory (Join-Path $taskWeb 'public') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRuntime "$Environment-server.log") -RedirectStandardError (Join-Path $taskRuntime "$Environment-server-error.log")
    $taskWorker = Start-Process -FilePath $taskPhp -ArgumentList @('-d','memory_limit=256M',('"'+(Join-Path $taskWeb 'artisan')+'"'),'queue:work','--sleep=3','--tries=2','--timeout=180','--max-time=28800',"--name=airbersih-$Environment") -WorkingDirectory $taskWeb -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRuntime "$Environment-worker.log") -RedirectStandardError (Join-Path $taskRuntime "$Environment-worker-error.log")
    @{ project=$taskRoot; server=$taskServer.Id; worker=$taskWorker.Id; environment=$Environment; port=$taskPort } | ConvertTo-Json | Set-Content -LiteralPath $taskStateFile -Encoding utf8
    $taskReady = $false
    for ($taskAttempt=0; $taskAttempt -lt 20; $taskAttempt++) { try { $taskResponse=Invoke-WebRequest -Uri "http://127.0.0.1:$taskPort/login" -UseBasicParsing -TimeoutSec 5; if ($taskResponse.StatusCode -eq 200) { $taskReady=$true; break } } catch { Start-Sleep -Milliseconds 300 } }
    if (!$taskReady) { & (Join-Path $PSScriptRoot 'stop-app.ps1') -Environment $Environment; throw 'Aplikasi belum siap. Periksa log privat pada private/runtime.' }
    Write-Output "Aplikasi siap: http://127.0.0.1:$taskPort"
} finally { $env:APP_ENV=$taskOldEnv }
