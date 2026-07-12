#Requires -Version 7.0
<#
.SYNOPSIS
    Watches OpenLP's service-file folder and uploads saved .osz files to the
    church website's service-tracking API (C4 in the service-automation report).

.DESCRIPTION
    Designed to run repeatedly from Windows Task Scheduler on the church PC.
    Each run scans the watch folder for recently saved OpenLP service files
    (.osz) and posts any it has not seen before to POST /api/services/openlp,
    which merge-imports them into the canonical church service record. This
    keeps OpenLP's exact song titles (the best source for catalog links)
    flowing back to the website with no operator effort, including any live
    edits made during the service.

    Unlike recordings, the same service file is often re-saved under the same
    name, so uploads are deduplicated by name AND last-write time AND size:
    a re-saved file is uploaded again, which is safe because the server-side
    import is merge-safe and idempotent.

.PARAMETER ConfigPath
    Path to the JSON config file. Defaults to config.json beside this script.
    Settings are read from the "service_files" section.

.PARAMETER StoreToken
    Prompt for the API token and store it encrypted (DPAPI on Windows) at the
    configured token_path, then exit. The token is shared with
    Upload-Recording.ps1 and needs both abilities (see README).

.PARAMETER DryRun
    Scan and report what would be uploaded without uploading anything.

.EXAMPLE
    pwsh -File Upload-ServiceFile.ps1 -DryRun
    pwsh -File Upload-ServiceFile.ps1
#>
[CmdletBinding()]
param(
    [string] $ConfigPath = (Join-Path $PSScriptRoot 'config.json'),
    [switch] $StoreToken,
    [switch] $DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Import-Module (Join-Path $PSScriptRoot 'ChurchPcCommon.psm1') -Force

# ---------------------------------------------------------------- config

if (-not (Test-Path $ConfigPath)) {
    throw "Config file not found at '$ConfigPath'. Copy config.example.json to config.json and edit it."
}

$config = Get-Content $ConfigPath -Raw | ConvertFrom-Json
$section = $config.service_files

$watchFolder = $section.watch_folder
$baseUrl = $config.base_url.TrimEnd('/')
$tokenPath = Resolve-ConfiguredPath $config.token_path -BaseDirectory $PSScriptRoot
$statePath = Resolve-ConfiguredPath $section.state_path -BaseDirectory $PSScriptRoot
$maxAgeHours = [double] $section.max_age_hours
$minAgeMinutes = [double] $section.min_age_minutes
$stabilitySeconds = [int] $section.stability_check_seconds

Initialize-Log -Path (Resolve-ConfiguredPath $section.log_path -BaseDirectory $PSScriptRoot)

if ($StoreToken) {
    Save-ApiToken -TokenPath $tokenPath
    exit 0
}

# ---------------------------------------------------------------- scanning

function Get-UploadKey {
    param([System.IO.FileInfo] $File)

    return '{0}|{1}|{2}' -f $File.Name, $File.LastWriteTimeUtc.ToString('o'), $File.Length
}

function Get-CandidateServiceFiles {
    if (-not (Test-Path $watchFolder)) {
        throw "Watch folder '$watchFolder' does not exist."
    }

    $cutoff = (Get-Date).AddHours(-$maxAgeHours)

    return @(Get-ChildItem -Path $watchFolder -File -Filter '*.osz' |
        Where-Object { $_.LastWriteTime -gt $cutoff })
}

# ---------------------------------------------------------------- main

Write-Log '--- Upload-ServiceFile run started ---'

$state = Get-UploadState -StatePath $statePath
$uploadedKeys = @($state | ForEach-Object key)
$candidates = @(Get-CandidateServiceFiles | Where-Object { (Get-UploadKey $_) -notin $uploadedKeys })

if ($candidates.Count -eq 0) {
    Write-Log 'No new service files to upload.'
    exit 0
}

if ($DryRun) {
    foreach ($file in $candidates) {
        Write-Log "[dry run] Would consider: $($file.Name) ($([math]::Round($file.Length / 1KB)) KB, modified $($file.LastWriteTime))"
    }
    exit 0
}

$token = Get-ApiToken -TokenPath $tokenPath
$failures = 0

foreach ($file in $candidates) {
    if (-not (Test-FileIsStable -File $file -MinAgeMinutes $minAgeMinutes -StabilitySeconds $stabilitySeconds)) {
        continue
    }

    try {
        Write-Log "Uploading $($file.Name) ($([math]::Round($file.Length / 1KB)) KB) to $baseUrl ..."

        # .osz is a zip; curl cannot guess that from the extension, so declare
        # the part's content type explicitly.
        $response = Invoke-MultipartUpload `
            -Url "$baseUrl/api/services/openlp" `
            -File $file `
            -Token $token `
            -ExpectedStatus '201' `
            -ContentType 'application/zip' `
            -RetryDelaySeconds 30

        $service = $response.data

        $state.Add([pscustomobject]@{
            key = Get-UploadKey $file
            file = $file.Name
            service_date = $service.date
            service = $service.service
            needs_review = $service.needs_review
            uploaded_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        })
        Save-UploadState -Uploaded $state -StatePath $statePath

        Write-Log "Import accepted: $($file.Name) -> $($service.date) $($service.service) (needs review: $($service.needs_review))"
        Send-Notification -Title 'Service file uploaded' -Body "$($file.Name) imported for $($service.date) ($($service.service))."
    } catch {
        $failures++
        Write-Log "Upload failed for $($file.Name): $($_.Exception.Message)" -Level ERROR
        Write-Log "At: $($_.ScriptStackTrace -replace "`n", ' | ')" -Level ERROR
        Send-Notification -Title 'Service file upload FAILED' -Body "$($file.Name): use the admin upload form. See the log for details."
    }
}

Write-Log '--- Upload-ServiceFile run finished ---'
exit ($failures -gt 0 ? 1 : 0)
