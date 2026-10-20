# ----------------------------------------------------------------
# Run this Powershell script to "build" OSPOS (one step at a time).
# The script moves to the repository root itself, so it can be
# invoked from any working directory.
# Use ".\tools\build\build-steps.ps1" from the repository root (if you are in
# another directory, provide a valid path to this script)
# The leading ".\" tells Powershell that you trust it.
# ----------------------------------------------------------------

# Run from the repository root (two levels up from tools/build/)
Set-Location -Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))

Write-Output "============================================================================="
Write-Output "1. Run Composer Install and npm install"
Write-Output "============================================================================="

composer install
npm install

Write-Output "============================================================================="
Write-Output "2. Build the stylesheets, scripts and fonts into public/assets"
Write-Output "============================================================================="
Read-Host -Prompt "Press any key to continue"

npm run build

Write-Output "============================================================================="
Write-Output "3. Restore configured .env file if it exists."
Write-Output "(If one is found in a folder located at  ../env/<name-of-ospos-root-folder>)"
Write-Output "============================================================================="

$currentfolder = Split-Path -Path (Get-Location) -Leaf
if (Test-Path -Path ../env/$currentfolder/.env -PathType Leaf) {
Copy ../env/$currentfolder/.env
}
