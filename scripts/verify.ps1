$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
& (Join-Path $PSScriptRoot 'stop-app.ps1') -Environment local
& (Join-Path $PSScriptRoot 'stop-app.ps1') -Environment e2e
$taskOldEnv=$env:APP_ENV
$taskPassed=$false
$taskSummary=@{result='running'; started_utc=[DateTime]::UtcNow.ToString('o'); environment='isolated Aiven test + localhost E2E'; checks=@('database guard','migration','Pint','frontend build','PHPUnit','encrypted backup/restore','concurrent payments','1000 synthetic connections','browser E2E')}
$taskSummaryPath=Join-Path $taskRoot 'docs\evidence\verification.json'
[IO.File]::WriteAllText($taskSummaryPath,($taskSummary | ConvertTo-Json -Depth 5),[Text.UTF8Encoding]::new($false))
Push-Location (Join-Path $taskRoot 'web')
try {
    $env:APP_ENV='testing'
    & php artisan air:check; if ($LASTEXITCODE) { throw 'Guard database gagal.' }
    & php artisan migrate:fresh --force --no-interaction; if ($LASTEXITCODE) { throw 'Migrasi database uji gagal.' }
    & php vendor/bin/pint --test --no-interaction; if ($LASTEXITCODE) { throw 'Pemeriksaan gaya kode gagal.' }
    & npm.cmd run build; if ($LASTEXITCODE) { throw 'Build gagal.' }
    & php artisan test --compact --log-junit=../docs/evidence/phpunit.xml; if ($LASTEXITCODE) { throw 'Tes backend gagal.' }
    $env:APP_ENV='local'
    & php artisan air:backup; if ($LASTEXITCODE) { throw 'Backup gagal.' }
    $env:APP_ENV='testing'
    & php artisan air:restore-test; if ($LASTEXITCODE) { throw 'Restore uji gagal.' }
    & php scripts/payment-race.php; if ($LASTEXITCODE) { throw 'Tes pembayaran paralel gagal.' }
    & php artisan air:restore-test; if ($LASTEXITCODE) { throw 'Reset fixture E2E gagal.' }
    & php scripts/benchmark-data.php; if ($LASTEXITCODE) { throw 'Dataset 1.000 sambungan gagal.' }
    & php scripts/create-test-fixtures.php; if ($LASTEXITCODE) { throw 'Fixture gagal.' }
    & (Join-Path $PSScriptRoot 'start-app.ps1') -Environment e2e
    & node tests/browser/flow.cjs; if ($LASTEXITCODE) { throw 'Tes browser gagal.' }
    $taskPassed=$true
    Write-Output 'PASS: build, backend, concurrency, backup/restore, dan alur browser.'
} finally {
    & (Join-Path $PSScriptRoot 'stop-app.ps1') -Environment e2e
    Pop-Location
    $env:APP_ENV=$taskOldEnv
    $taskSummary.result=if($taskPassed){'passed'}else{'failed'}
    $taskSummary.finished_utc=[DateTime]::UtcNow.ToString('o')
    [IO.File]::WriteAllText($taskSummaryPath,($taskSummary | ConvertTo-Json -Depth 5),[Text.UTF8Encoding]::new($false))
}
