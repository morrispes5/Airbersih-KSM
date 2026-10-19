$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskPreviousEnv=@{ APP_ENV=$env:APP_ENV; AIR_MODE=$env:AIR_MODE; AIR_DOCUMENTS_DISK=$env:AIR_DOCUMENTS_DISK; FILESYSTEM_DISK=$env:FILESYSTEM_DISK }
$taskFixtureReady=$false
Push-Location (Join-Path $taskRoot 'web')
try {
    & (Join-Path $PSScriptRoot 'stop-app.ps1') -Environment e2e
    $env:APP_ENV='e2e'
    $env:AIR_MODE='operational'
    $env:AIR_DOCUMENTS_DISK='cloud'
    $env:FILESYSTEM_DISK='cloud'
    & php artisan migrate:fresh --force --no-interaction; if($LASTEXITCODE){throw 'Reset target uji gagal'}
    & php scripts/operational-flow-fixtures.php --create; if($LASTEXITCODE){throw 'Fixture mode operasional gagal'}
    $taskFixtureReady=$true
    & (Join-Path $PSScriptRoot 'start-app.ps1') -Environment e2e
    & node tests/browser/operational-flow.cjs; if($LASTEXITCODE){throw 'Browser mode operasional gagal; periksa diagnostik privat'}
    & php scripts/operational-flow-fixtures.php --assert; if($LASTEXITCODE){throw 'Rekonsiliasi alur operasional uji gagal'}
} finally {
    try {
        & (Join-Path $PSScriptRoot 'stop-app.ps1') -Environment e2e
        if($taskFixtureReady){ & php scripts/operational-flow-fixtures.php --cleanup; if($LASTEXITCODE){throw 'Pembersihan file uji gagal'} }
    } finally {
        $env:APP_ENV='testing'
        $env:AIR_MODE='simulation'
        $env:AIR_DOCUMENTS_DISK='local'
        $env:FILESYSTEM_DISK='local'
        try { & php artisan air:restore-test; if($LASTEXITCODE){throw 'Pemulihan fixture baseline gagal'} }
        finally {
            $env:APP_ENV=$taskPreviousEnv.APP_ENV
            $env:AIR_MODE=$taskPreviousEnv.AIR_MODE
            $env:AIR_DOCUMENTS_DISK=$taskPreviousEnv.AIR_DOCUMENTS_DISK
            $env:FILESYSTEM_DISK=$taskPreviousEnv.FILESYSTEM_DISK
            Pop-Location
        }
    }
}
