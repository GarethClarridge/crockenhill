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

.PARAMETER StoreToken
    Prompt for the API token and store it encrypted (DPAPI on Windows) at the
    configured token_path, then exit. Run this once during installation.

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

# ---------------------------------------------------------------- utilities

function Resolve-ConfiguredPath {
    param([string] $Path)

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return $Path
    }

    return Join-Path $PSScriptRoot $Path
}

$script:LogPath = $null

function Write-Log {
    param(
        [string] $Message,
        [ValidateSet('INFO', 'WARN', 'ERROR')] [string] $Level = 'INFO'
    )

    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Write-Host $line

    if ($script:LogPath) {
        Add-Content -Path $script:LogPath -Value $line
    }
}

function Send-Notification {
    param([string] $Title, [string] $Body)

    # Best-effort only: never let a notification failure break an upload run.
    if (-not $IsWindows) {
        return
    }

    try {
        $template = @"
<toast><visual><binding template="ToastGeneric">
<text>$([System.Security.SecurityElement]::Escape($Title))</text>
<text>$([System.Security.SecurityElement]::Escape($Body))</text>
</binding></visual></toast>
"@
        $xml = [Windows.Data.Xml.Dom.XmlDocument, Windows.Data.Xml.Dom.XmlDocument, ContentType = WindowsRuntime]::new()
        $xml.LoadXml($template)
        $toast = [Windows.UI.Notifications.ToastNotification, Windows.UI.Notifications, ContentType = WindowsRuntime]::new($xml)
        [Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime]::CreateToastNotifier('Crockenhill Upload').Show($toast)
    } catch {
        Write-Log "Toast notification failed (non-fatal): $($_.Exception.Message)" -Level WARN
    }
}

# ---------------------------------------------------------------- config

if (-not (Test-Path $ConfigPath)) {
    throw "Config file not found at '$ConfigPath'. Copy config.example.json to config.json and edit it."
}

$config = Get-Content $ConfigPath -Raw | ConvertFrom-Json

$watchFolder = $config.watch_folder
$baseUrl = $config.base_url.TrimEnd('/')
$tokenPath = Resolve-ConfiguredPath $config.token_path
$statePath = Resolve-ConfiguredPath $config.state_path
$script:LogPath = Resolve-ConfiguredPath $config.log_path
$extensions = @($config.extensions | ForEach-Object { $_.ToLowerInvariant() })
$maxAgeHours = [double] $config.max_age_hours
$minAgeMinutes = [double] $config.min_age_minutes
$stabilitySeconds = [int] $config.stability_check_seconds

# ---------------------------------------------------------------- token

if ($StoreToken) {
    $secure = Read-Host -Prompt 'Paste the API token (input hidden)' -AsSecureString

    if (-not $IsWindows) {
        Write-Warning 'Not running on Windows: the token file will NOT be DPAPI-protected on this platform.'
    }

    $secure | Export-Clixml -Path $tokenPath
    Write-Host "Token stored at $tokenPath"
    exit 0
}

function Get-ApiToken {
    # Environment override is intended for development/testing only.
    if ($env:CROCKENHILL_API_TOKEN) {
        return $env:CROCKENHILL_API_TOKEN
    }

    if (-not (Test-Path $tokenPath)) {
        throw "No token found at '$tokenPath'. Run this script with -StoreToken first."
    }

    $secure = Import-Clixml -Path $tokenPath

    return [System.Net.NetworkCredential]::new('', $secure).Password
}

# ---------------------------------------------------------------- state

function Get-UploadState {
    if (Test-Path $statePath) {
        $raw = Get-Content $statePath -Raw | ConvertFrom-Json

        # The leading comma stops PowerShell unrolling the list into the
        # pipeline (an empty list would otherwise arrive at the caller as $null).
        return , [System.Collections.Generic.List[object]] @($raw.uploaded)
    }

    return , [System.Collections.Generic.List[object]]::new()
}

function Save-UploadState {
    param([System.Collections.Generic.List[object]] $Uploaded)

    @{ uploaded = @($Uploaded) } | ConvertTo-Json -Depth 5 | Set-Content -Path $statePath
}

# ---------------------------------------------------------------- scanning

function Test-FileIsStable {
    param([System.IO.FileInfo] $File)

    if ($File.LastWriteTime -gt (Get-Date).AddMinutes(-$minAgeMinutes)) {
        Write-Log "Skipping $($File.Name): modified less than $minAgeMinutes minutes ago (still recording?)"

        return $false
    }

    $sizeBefore = $File.Length
    Start-Sleep -Seconds $stabilitySeconds
    $File.Refresh()

    if ($File.Length -ne $sizeBefore) {
        Write-Log "Skipping $($File.Name): size still changing ($sizeBefore -> $($File.Length) bytes)"

        return $false
    }

    # OBS (and the remuxer) hold an exclusive write handle until finished.
    try {
        $stream = [System.IO.File]::Open($File.FullName, 'Open', 'Read', 'Read')
        $stream.Dispose()
    } catch {
        Write-Log "Skipping $($File.Name): file is still locked by another process"

        return $false
    }

    return $true
}

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

# ---------------------------------------------------------------- upload

function Get-CurlBinary {
    # On Windows the real curl lives at curl.exe (bundled since Windows 10 1803).
    $name = $IsWindows ? 'curl.exe' : 'curl'
    $cmd = Get-Command $name -CommandType Application -ErrorAction SilentlyContinue

    if (-not $cmd) {
        throw "Could not find $name on PATH. It ships with Windows 10 1803+ and macOS."
    }

    return $cmd.Source
}

function Invoke-RecordingUpload {
    param([System.IO.FileInfo] $File, [string] $Token)

    $curl = Get-CurlBinary
    $responseFile = New-TemporaryFile

    try {
        # curl streams the multipart body from disk (Invoke-RestMethod would
        # buffer a multi-GB recording in memory). Livestream uploads are
        # rate-limited to 1/minute, so retries wait out the window.
        $curlArgs = @(
            '--silent', '--show-error'
            '--request', 'POST'
            '--header', "Authorization: Bearer $Token"
            '--header', 'Accept: application/json'
            '--form', "file=@$($File.FullName)"
            '--retry', '3'
            '--retry-delay', '90'
            '--max-time', '7200'
            '--write-out', '%{http_code}'
            '--output', $responseFile.FullName
            "$baseUrl/api/media/livestream"
        )

        Write-Log "Uploading $($File.Name) ($([math]::Round($File.Length / 1GB, 2)) GB) to $baseUrl ..."
        $httpCode = & $curl @curlArgs
        $body = Get-Content $responseFile.FullName -Raw -ErrorAction SilentlyContinue

        if ($LASTEXITCODE -ne 0) {
            throw "curl failed with exit code $LASTEXITCODE (HTTP $httpCode): $body"
        }

        if ($httpCode -ne '202') {
            throw "Upload rejected with HTTP $httpCode`: $body"
        }

        return ($body | ConvertFrom-Json)
    } finally {
        Remove-Item $responseFile.FullName -ErrorAction SilentlyContinue
    }
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

$state = Get-UploadState
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

$token = Get-ApiToken
$failures = 0

foreach ($file in $candidates) {
    if (-not (Test-FileIsStable -File $file)) {
        continue
    }

    try {
        $result = Invoke-RecordingUpload -File $file -Token $token

        $state.Add([pscustomobject]@{
            file = $file.Name
            size = $file.Length
            last_write_utc = $file.LastWriteTimeUtc.ToString('o')
            processing_id = $result.processing_id
            uploaded_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        })
        Save-UploadState -Uploaded $state

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
        Send-Notification -Title 'Recording upload FAILED' -Body "$($file.Name): use the admin upload form. See $($script:LogPath)."
    }
}

Write-Log '--- Upload-Recording run finished ---'
exit ($failures -gt 0 ? 1 : 0)
