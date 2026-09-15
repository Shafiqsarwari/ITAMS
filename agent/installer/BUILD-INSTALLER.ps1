$ErrorActionPreference = 'Stop'

$Root = Resolve-Path (Join-Path $PSScriptRoot '..')
$AgentProject = Join-Path $Root 'AssetInventoryAgent\AssetInventoryAgent.csproj'
$PublishDir = Join-Path $Root 'AssetInventoryAgent\bin\Release\net8.0-windows\win-x64\publish'
$InstallerScript = Join-Path $PSScriptRoot 'AssetInventoryAgent.iss'
$TimestampUrl = if ($env:AGENT_SIGN_TIMESTAMP_URL) { $env:AGENT_SIGN_TIMESTAMP_URL } else { 'http://timestamp.digicert.com' }

function Get-SigningCertificate {
    if ($env:AGENT_SIGN_CERT_PATH) {
        if (-not (Test-Path $env:AGENT_SIGN_CERT_PATH)) {
            throw "Signing certificate was not found: $env:AGENT_SIGN_CERT_PATH"
        }

        $password = if ($env:AGENT_SIGN_CERT_PASSWORD) {
            ConvertTo-SecureString $env:AGENT_SIGN_CERT_PASSWORD -AsPlainText -Force
        } else {
            Read-Host 'PFX password' -AsSecureString
        }

        return New-Object System.Security.Cryptography.X509Certificates.X509Certificate2(
            $env:AGENT_SIGN_CERT_PATH,
            $password,
            [System.Security.Cryptography.X509Certificates.X509KeyStorageFlags]::Exportable
        )
    }

    if ($env:AGENT_SIGN_CERT_THUMBPRINT) {
        $thumbprint = $env:AGENT_SIGN_CERT_THUMBPRINT -replace '\s', ''
        $cert = Get-ChildItem Cert:\CurrentUser\My, Cert:\LocalMachine\My |
            Where-Object { ($_.Thumbprint -replace '\s', '') -ieq $thumbprint } |
            Select-Object -First 1
        if (-not $cert) {
            throw "Signing certificate thumbprint was not found: $env:AGENT_SIGN_CERT_THUMBPRINT"
        }

        return $cert
    }

    return $null
}

function Sign-FileIfConfigured {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $false)]$Certificate
    )

    if (-not $Certificate) {
        Write-Host "Skipping code signing for $Path. Set AGENT_SIGN_CERT_PATH or AGENT_SIGN_CERT_THUMBPRINT to sign." -ForegroundColor Yellow
        return
    }

    Write-Host "Signing $Path..." -ForegroundColor Cyan
    $signature = Set-AuthenticodeSignature -FilePath $Path -Certificate $Certificate -TimestampServer $TimestampUrl -HashAlgorithm SHA256
    if ($signature.Status -ne 'Valid') {
        throw "Code signing failed for $Path. Status: $($signature.Status). $($signature.StatusMessage)"
    }
}

Write-Host 'Building IT Asset Maintenance Management System Agent...' -ForegroundColor Cyan

if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) {
    throw 'dotnet was not found. Install .NET 8 SDK, then run this script again.'
}

$iscc = Get-Command ISCC.exe -ErrorAction SilentlyContinue
if (-not $iscc) {
    $candidates = @(
        "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe",
        'C:\Program Files (x86)\Inno Setup 6\ISCC.exe',
        'C:\Program Files\Inno Setup 6\ISCC.exe'
    )
    $iscc = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
}

if (-not $iscc) {
    throw 'Inno Setup compiler was not found. Install Inno Setup 6, then run this script again.'
}

dotnet publish $AgentProject -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:EnableCompressionInSingleFile=true
if ($LASTEXITCODE -ne 0) {
    throw "dotnet publish failed with exit code $LASTEXITCODE."
}

if (-not (Test-Path (Join-Path $PublishDir 'AssetInventoryAgent.exe'))) {
    throw "Publish failed. Agent EXE was not found at $PublishDir"
}

$SigningCertificate = Get-SigningCertificate
Sign-FileIfConfigured -Path (Join-Path $PublishDir 'AssetInventoryAgent.exe') -Certificate $SigningCertificate

Write-Host 'Compiling setup EXE...' -ForegroundColor Cyan
& $iscc $InstallerScript
if ($LASTEXITCODE -ne 0) {
    throw "Installer compile failed with exit code $LASTEXITCODE."
}

$SetupExe = Join-Path $PSScriptRoot 'dist\ITAssetMaintenanceManagementSystemAgentSetup.exe'
if (Test-Path $SetupExe) {
    Sign-FileIfConfigured -Path $SetupExe -Certificate $SigningCertificate
    Write-Host "Installer created: $SetupExe" -ForegroundColor Green
} else {
    throw 'Installer compile finished, but the setup EXE was not found.'
}
