#Requires -Version 7.0
<#
.SYNOPSIS
    Watches the OBS recording folder and uploads finished recordings to the
    church website's media-processing API (C1 in the service-automation report).

.DESCRIPTION
    Designed to run repeatedly from Windows Task Scheduler on the church PC.
    Each run scans the watch folder for recent recordings that have finished
    writing (stable size, past a minimum age), uploads any it has not seen
    before to POST /api/media/livestream, then confirms processing has started.

    State is kept in a JSON file next to the script so a recording is only
    ever uploaded once. If this script fails, the manual admin upload form
    still works unchanged.

.PARAMETER ConfigPath
    Path to the JSON config file. Defaults to config.json beside this script.
    Settings are read from the "recordings" section.

.PARAMETER StoreToken
    Prompt for the API token and store it encrypted (DPAPI on Windows) at the
    configured token_path, then exit. Run this once during installation; the
    token is shared with Upload-ServiceFile.ps1.

.PARAMETER DryRun
    Scan and report what would be uploaded without uploading anything.

.EXAMPLE
    pwsh -File Upload-Recording.ps1 -StoreToken
    pwsh -File Upload-Recording.ps1 -DryRun
    pwsh -File Upload-Recording.ps1
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
$section = $config.recordings

$watchFolder = $section.watch_folder
$baseUrl = $config.base_url.TrimEnd('/')
$tokenPath = Resolve-ConfiguredPath $config.token_path -BaseDirectory $PSScriptRoot
$statePath = Resolve-ConfiguredPath $section.state_path -BaseDirectory $PSScriptRoot
$extensions = @($section.extensions | ForEach-Object { $_.ToLowerInvariant() })
$maxAgeHours = [double] $section.max_age_hours
$minAgeMinutes = [double] $section.min_age_minutes
$stabilitySeconds = [int] $section.stability_check_seconds

Initialize-Log -Path (Resolve-ConfiguredPath $section.log_path -BaseDirectory $PSScriptRoot)

if ($StoreToken) {
    Save-ApiToken -TokenPath $tokenPath
    exit 0
}

# ---------------------------------------------------------------- scanning

function Get-CandidateRecordings {
    if (-not (Test-Path $watchFolder)) {
        throw "Watch folder '$watchFolder' does not exist."
    }

    $cutoff = (Get-Date).AddHours(-$maxAgeHours)

    $recent = Get-ChildItem -Path $watchFolder -File |
        Where-Object { $extensions -contains $_.Extension.TrimStart('.').ToLowerInvariant() } |
        Where-Object { $_.LastWriteTime -gt $cutoff }

    # Prefer the remuxed .mp4 over the original .mkv when both exist: OBS's
    # "automatically remux to mp4" produces a same-named sibling, and the mkv
    # is still present while (or after) remuxing happens.
    $mp4Basenames = @($recent | Where-Object Extension -eq '.mp4' | ForEach-Object BaseName)

    return @($recent | Where-Object {
        $_.Extension -ne '.mkv' -or $_.BaseName -notin $mp4Basenames
    })
}

function Confirm-ProcessingStarted {
    param([string] $ProcessingId, [string] $Token)

    $headers = @{ Authorization = "Bearer $Token"; Accept = 'application/json' }
    $statusUrl = "$baseUrl/api/media/processing/$ProcessingId/status"

    foreach ($attempt in 1..6) {
        try {
            $status = Invoke-RestMethod -Uri $statusUrl -Headers $headers -TimeoutSec 30

            if ($status.found) {
                Write-Log "Processing status: $($status.status)"

                return $true
            }
        } catch {
            Write-Log "Status check attempt $attempt failed: $($_.Exception.Message)" -Level WARN
        }

        Start-Sleep -Seconds 10
    }

    return $false
}

# ---------------------------------------------------------------- main

Write-Log '--- Upload-Recording run started ---'

$state = Get-UploadState -StatePath $statePath
$uploadedNames = @($state | ForEach-Object file)
$candidates = @(Get-CandidateRecordings | Where-Object { $_.Name -notin $uploadedNames })

if ($candidates.Count -eq 0) {
    Write-Log 'No new recordings to upload.'
    exit 0
}

if ($DryRun) {
    foreach ($file in $candidates) {
        Write-Log "[dry run] Would consider: $($file.Name) ($([math]::Round($file.Length / 1GB, 2)) GB, modified $($file.LastWriteTime))"
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
        Write-Log "Uploading $($file.Name) ($([math]::Round($file.Length / 1GB, 2)) GB) to $baseUrl ..."

        # Livestream uploads are rate-limited server-side to 1/minute, so
        # curl's retries wait out the window.
        $result = Invoke-MultipartUpload `
            -Url "$baseUrl/api/media/livestream" `
            -File $file `
            -Token $token `
            -ExpectedStatus '202' `
            -RetryDelaySeconds 90

        $state.Add([pscustomobject]@{
            file = $file.Name
            size = $file.Length
            last_write_utc = $file.LastWriteTimeUtc.ToString('o')
            processing_id = $result.processing_id
            uploaded_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        })
        Save-UploadState -Uploaded $state -StatePath $statePath

        Write-Log "Upload accepted: $($file.Name) -> processing_id $($result.processing_id)"

        if (Confirm-ProcessingStarted -ProcessingId $result.processing_id -Token $token) {
            Send-Notification -Title 'Recording uploaded' -Body "$($file.Name) uploaded; processing has started."
        } else {
            Write-Log 'Upload accepted but processing status could not be confirmed yet.' -Level WARN
            Send-Notification -Title 'Recording uploaded' -Body "$($file.Name) uploaded; check the admin dashboard for progress."
        }
    } catch {
        $failures++
        Write-Log "Upload failed for $($file.Name): $($_.Exception.Message)" -Level ERROR
        Write-Log "At: $($_.ScriptStackTrace -replace "`n", ' | ')" -Level ERROR
        Send-Notification -Title 'Recording upload FAILED' -Body "$($file.Name): use the admin upload form. See the log for details."
    }
}

Write-Log '--- Upload-Recording run finished ---'
exit ($failures -gt 0 ? 1 : 0)
