# Photo Select Protocol Handler
# Receives a photo-select:// URI and opens Windows Explorer with the file selected.
#
# Usage (called automatically by Windows when clicking a photo-select:// link):
#   powershell -ExecutionPolicy Bypass -File photo-select.ps1 "photo-select:///F:/Photos/2020/file.jpg"

param([string]$uri)

if (-not $uri) { exit 1 }

# Strip protocol prefix
$path = $uri -replace '^photo-select:/+', ''

# URL-decode (%20 → space, %28 → (, etc.)
$path = [System.Uri]::UnescapeDataString($path)

# Convert forward slashes to backslashes
$path = $path -replace '/', '\'

# Open Explorer with the file selected
if (Test-Path $path) {
    Start-Process explorer.exe -ArgumentList "/select,`"$path`""
} else {
    # If file doesn't exist, open the parent directory
    $dir = Split-Path $path -Parent
    if (Test-Path $dir) {
        Start-Process explorer.exe -ArgumentList "`"$dir`""
    }
}
