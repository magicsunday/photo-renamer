# Registers the photo-select:// URI protocol handler for Windows.
# Run once as Administrator (or current user for HKCU).
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File install-protocol.ps1
#   powershell -ExecutionPolicy Bypass -File install-protocol.ps1 -HandlerPath "D:\tools\photo-select.ps1"
#   powershell -ExecutionPolicy Bypass -File install-protocol.ps1 -Uninstall

param(
    [string]$HandlerPath = "",
    [switch]$Uninstall
)

$protocolName = "photo-select"
$regPath = "HKCU:\Software\Classes\$protocolName"

if ($Uninstall) {
    if (Test-Path $regPath) {
        Remove-Item -Path $regPath -Recurse -Force
        Write-Host "Protocol '$protocolName' unregistered."
    } else {
        Write-Host "Protocol '$protocolName' not found."
    }
    exit 0
}

# Auto-detect handler path if not specified
if (-not $HandlerPath) {
    $HandlerPath = Join-Path $PSScriptRoot "photo-select.ps1"
}

if (-not (Test-Path $HandlerPath)) {
    Write-Error "Handler script not found: $HandlerPath"
    exit 1
}

# Resolve-Path adds "Microsoft.PowerShell.Core\FileSystem::" prefix for UNC paths — strip it
$HandlerPath = (Resolve-Path $HandlerPath).ProviderPath
$command = "powershell.exe -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$HandlerPath`" `"%1`""

# Register protocol
New-Item -Path $regPath -Force | Out-Null
Set-ItemProperty -Path $regPath -Name "(Default)" -Value "URL:Photo Select Protocol"
Set-ItemProperty -Path $regPath -Name "URL Protocol" -Value ""

New-Item -Path "$regPath\shell\open\command" -Force | Out-Null
Set-ItemProperty -Path "$regPath\shell\open\command" -Name "(Default)" -Value $command

Write-Host "Protocol '$protocolName' registered."
Write-Host "Handler: $HandlerPath"
Write-Host ""
Write-Host "Test it by clicking a photo-select:// link in your terminal,"
Write-Host "or run: start photo-select:///F:/test/file.jpg"
