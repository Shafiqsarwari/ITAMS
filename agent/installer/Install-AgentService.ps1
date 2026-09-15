param(
    [Parameter(Mandatory = $true)]
    [string] $ExePath
)

$ErrorActionPreference = 'Stop'
$serviceName = 'EndpointHealthAgent'
$displayName = 'IT Asset Maintenance Management System Agent'
$oldServiceNames = @('Endpoint Health Agent', 'Asset Inventory Agent', 'AssetInventoryAgent')
$oldTaskNames = @('Endpoint Health Agent', 'Asset Inventory Agent', 'Asset Inventory Agent Retry')
$statusPath = Join-Path (Split-Path -Parent $ExePath) 'install-status.txt'
$logPath = Join-Path (Split-Path -Parent $ExePath) 'install-service.log'

function Write-AgentInstallLog {
    param([string] $Message)
    Add-Content -Path $logPath -Value "$((Get-Date).ToString('s')) $Message" -Encoding UTF8
}

function Invoke-IgnoredNative {
    param(
        [string] $FilePath,
        [string[]] $Arguments
    )

    try {
        $previousPreference = $ErrorActionPreference
        $ErrorActionPreference = 'SilentlyContinue'
        & $FilePath @Arguments *> $null
        $ErrorActionPreference = $previousPreference
    } catch {
        $ErrorActionPreference = $previousPreference
        Write-AgentInstallLog "Ignored native command failure: $FilePath $($Arguments -join ' ') :: $($_.Exception.Message)"
    }
}

function Invoke-RequiredNative {
    param(
        [string] $FilePath,
        [string[]] $Arguments,
        [int[]] $AllowedExitCodes = @(0)
    )

    $output = & $FilePath @Arguments 2>&1
    $exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
    Write-AgentInstallLog "$FilePath $($Arguments -join ' ') => exit $exitCode $($output -join ' ')"
    if ($AllowedExitCodes -notcontains $exitCode) {
        throw "$FilePath failed with exit code $exitCode. $($output -join ' ')"
    }
}

try {
    Write-AgentInstallLog "Installing service $serviceName from $ExePath"

    foreach ($taskName in $oldTaskNames) {
        Invoke-IgnoredNative 'schtasks.exe' @('/End', '/TN', $taskName)
        Invoke-IgnoredNative 'schtasks.exe' @('/Delete', '/TN', $taskName, '/F')
    }

    foreach ($name in @($serviceName) + $oldServiceNames) {
        $existing = Get-Service -Name $name -ErrorAction SilentlyContinue
        if ($existing) {
            if ($existing.Status -ne 'Stopped') {
                Stop-Service -Name $name -Force -ErrorAction SilentlyContinue
                try {
                    $existing.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
                } catch {
                    Write-AgentInstallLog "Service $name did not stop cleanly before delete: $($_.Exception.Message)"
                }
            }

            Invoke-IgnoredNative 'sc.exe' @('delete', $name)
            Start-Sleep -Seconds 2
        }
    }

    New-Service `
        -Name $serviceName `
        -DisplayName $displayName `
        -BinaryPathName "`"$ExePath`" --service" `
        -StartupType Automatic `
        -Description 'Monitors endpoint health, update state, installed software, and continuous connectivity.' | Out-Null
    Write-AgentInstallLog "New-Service created $serviceName"

    Invoke-RequiredNative 'sc.exe' @('description', $serviceName, 'Monitors endpoint health, update state, installed software, and continuous connectivity.')
    Invoke-RequiredNative 'sc.exe' @('failure', $serviceName, 'reset=', '86400', 'actions=', 'restart/60000/restart/60000/restart/60000')
    Invoke-RequiredNative 'sc.exe' @('failureflag', $serviceName, '1')
    Invoke-RequiredNative 'sc.exe' @('start', $serviceName) -AllowedExitCodes @(0, 1056)

    $deadline = (Get-Date).AddSeconds(30)
    do {
        Start-Sleep -Seconds 1
        $service = Get-Service -Name $serviceName -ErrorAction Stop
    } while ($service.Status -eq 'StartPending' -and (Get-Date) -lt $deadline)

    if ($service.Status -ne 'Running') {
        throw "Service $serviceName is $($service.Status) after start."
    }

    @(
        "Product=IT Asset Maintenance Management System Agent"
        "Version=1.2.14"
        "ServiceName=$serviceName"
        "DisplayName=$displayName"
        "Status=$($service.Status)"
        "InstalledAt=$((Get-Date).ToString('s'))"
        "ExePath=$ExePath"
    ) | Set-Content -Path $statusPath -Encoding UTF8

    Write-AgentInstallLog "Service $serviceName installed and running."
} catch {
    Write-AgentInstallLog "FAILED: $($_.Exception.Message)"
    throw
}
