param([ValidateSet('local','e2e','operational')][string]$Environment = 'operational')
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskStateFile = Join-Path $taskRoot "private\runtime\$Environment.json"
if (!(Test-Path -LiteralPath $taskStateFile)) { return }
$taskState = Get-Content -LiteralPath $taskStateFile -Raw | ConvertFrom-Json
if ($taskState.project -ne $taskRoot) { throw 'Identitas proses tidak sesuai proyek.' }
foreach ($taskProcessId in @($taskState.server,$taskState.worker)) {
    $taskProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $taskProcessId" -ErrorAction SilentlyContinue
    if ($taskProcess -and $taskProcess.Name -eq 'php.exe' -and $taskProcess.CommandLine.IndexOf($taskRoot,[StringComparison]::OrdinalIgnoreCase) -ge 0) { Stop-Process -Id $taskProcessId -Force -ErrorAction SilentlyContinue }
}
Remove-Item -LiteralPath $taskStateFile
Write-Output "Proses Air Bersih ($Environment) dihentikan."
