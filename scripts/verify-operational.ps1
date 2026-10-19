param([switch]$ResumeAfterRegression, [string]$VerificationPython)
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskPreviousEnvironment = $env:APP_ENV
$taskRecoveryAttempted = $false
$taskSucceeded = $false
$taskSteps = [Collections.Generic.List[object]]::new()
$taskStartedUtc = [DateTimeOffset]::UtcNow

function Invoke-AirCheck([string]$Name, [scriptblock]$Check) {
    $taskCheckStarted = [DateTimeOffset]::UtcNow
    try {
        & $Check
        if ($LASTEXITCODE -ne 0) { throw "Pemeriksaan gagal: $Name" }
        $taskSteps.Add(@{ name=$Name; result='passed'; elapsed_seconds=[Math]::Round(([DateTimeOffset]::UtcNow-$taskCheckStarted).TotalSeconds,1) })
    } catch {
        $taskSteps.Add(@{ name=$Name; result='failed'; elapsed_seconds=[Math]::Round(([DateTimeOffset]::UtcNow-$taskCheckStarted).TotalSeconds,1) })
        throw
    }
}

Push-Location $taskRoot
try {
    # Standalone verifier scripts select their own private env file.
    $env:APP_ENV=$null
    if ($ResumeAfterRegression) {
        $taskPriorRegression=Get-Content -LiteralPath (Join-Path $taskRoot 'docs/evidence/verification.json') -Raw | ConvertFrom-Json
        if ($taskPriorRegression.result -ne 'passed') { throw 'Regresi lengkap sebelumnya belum lulus.' }
        $taskRegressionFinished=[DateTimeOffset]$taskPriorRegression.finished_utc
        $taskNewBackendFiles=@(Get-ChildItem (Join-Path $taskRoot 'web/app'),(Join-Path $taskRoot 'web/config'),(Join-Path $taskRoot 'web/database'),(Join-Path $taskRoot 'web/routes'),(Join-Path $taskRoot 'web/scripts'),(Join-Path $taskRoot 'web/tests') -Filter '*.php' -Recurse | Where-Object { $_.LastWriteTimeUtc -gt $taskRegressionFinished.UtcDateTime })
        if ($taskNewBackendFiles.Count) { throw 'Backend berubah setelah regresi; jalankan ulang rangkaian tanpa ResumeAfterRegression.' }
        Invoke-AirCheck 'original operational integrity baseline unchanged' { & php web/scripts/operational-integrity.php --compare }
        $taskSteps.Add(@{ name='full baseline regression'; result='passed'; reused=$true; source_finished_utc=$taskPriorRegression.finished_utc })
    } else {
        Invoke-AirCheck 'operational integrity baseline' { & php web/scripts/operational-integrity.php --capture }
        Invoke-AirCheck 'full baseline regression' { & (Join-Path $PSScriptRoot 'verify.ps1') }
    }
    Invoke-AirCheck 'current lint and frontend build' {
        Push-Location (Join-Path $taskRoot 'web')
        try {
            & php vendor/bin/pint --test --no-interaction; if($LASTEXITCODE){throw 'Pint gagal'}
            & npm.cmd run build
        } finally { Pop-Location }
    }
    Invoke-AirCheck 'operational financial browser flow on isolated test target' { & (Join-Path $PSScriptRoot 'verify-operational-flow.ps1') }
    Invoke-AirCheck 'operational server and worker' { & (Join-Path $PSScriptRoot 'start-app.ps1') -Environment operational }
    Invoke-AirCheck 'operational private storage' { & php web/scripts/verify-cloud.php --operational }
    Invoke-AirCheck 'isolated private test storage' { & php web/scripts/verify-cloud.php }
    Invoke-AirCheck 'operational source hashes and roles' { & php web/scripts/verify-operational-state.php --files }
    Invoke-AirCheck 'operational desktop mobile exports and backup UI' { & node web/tests/browser/operational.cjs --backup }
    $taskRecoveryAttempted=$true
    try {
        Invoke-AirCheck 'actual cloud recovery from independent Aiven copy' { & php web/scripts/verify-operational-recovery.php }
    } finally {
        # Source copies may exist in test storage even if a later restore check fails.
        $env:APP_ENV='testing'
        Push-Location (Join-Path $taskRoot 'web')
        try { Invoke-AirCheck 'restore synthetic test baseline' { & php artisan air:restore-test } } finally { Pop-Location }
        Invoke-AirCheck 'remove real source copies from test bucket' { & php web/scripts/cleanup-recovery-test.php }
        $env:APP_ENV=$null
    }
    Invoke-AirCheck 'operational business data unchanged' { & php web/scripts/operational-integrity.php --compare }
    Invoke-AirCheck 'final operational source readback' { & php web/scripts/verify-operational-state.php --files }
    if (!$VerificationPython) {
        $taskBundledPython=Join-Path $env:USERPROFILE '.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe'
        $VerificationPython=if(Test-Path -LiteralPath $taskBundledPython){$taskBundledPython}else{(Get-Command python.exe).Source}
    }
    Invoke-AirCheck 'A4 invoice terbilang and report fields' { & $VerificationPython scripts/verify-pdfs.py }
    $taskSucceeded=$true
    Write-Output 'PASS: rangkaian M10.5/M10.6 teknis selesai; pengesahan fakta lapangan tetap oleh pengurus.'
} finally {
    $taskResult=@{
        started_utc=$taskStartedUtc.ToString('o')
        finished_utc=[DateTimeOffset]::UtcNow.ToString('o')
        finished_wib=[DateTimeOffset]::UtcNow.ToOffset([TimeSpan]::FromHours(7)).ToString('o')
        result=$(if($taskSucceeded){'passed'}else{'failed'})
        checks=$taskSteps.ToArray()
        operational_transactions_created_by_tests=$false
        actual_recovery_attempted=$taskRecoveryAttempted
        human_uat='pending factual verification by pengurus'
    }
    [IO.File]::WriteAllText((Join-Path $taskRoot 'docs/evidence/m10-acceptance.json'),($taskResult | ConvertTo-Json -Depth 6),[Text.UTF8Encoding]::new($false))
    $env:APP_ENV=$taskPreviousEnvironment
    Pop-Location
}
