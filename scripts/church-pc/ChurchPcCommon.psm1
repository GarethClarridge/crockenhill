<#
.SYNOPSIS
    Shared helpers for the church PC automation scripts (Upload-Recording.ps1,
    Upload-ServiceFile.ps1). Imported by both; not run directly.
#>

Set-StrictMode -Version Latest

$script:LogPath = $null

function Resolve-ConfiguredPath {
    param(
        [Parameter(Mandatory)] [string] $Path,
        [Parameter(Mandatory)] [string] $BaseDirectory
    )

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return $Path
    }

    return Join-Path $BaseDirectory $Path
}

function Initialize-Log {
    param([Parameter(Mandatory)] [string] $Path)

    $script:LogPath = $Path
}

function Write-Log {
    param(
        [Parameter(Mandatory)] [string] $Message,
        [ValidateSet('INFO', 'WARN', 'ERROR')] [string] $Level = 'INFO'
    )

    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Write-Host $line

    if ($script:LogPath) {
        Add-Content -Path $script:LogPath -Value $line
    }
}

function Send-Notification {
    param(
        [Parameter(Mandatory)] [string] $Title,
        [Parameter(Mandatory)] [string] $Body
    )

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

function Save-ApiToken {
    param([Parameter(Mandatory)] [string] $TokenPath)

    $secure = Read-Host -Prompt 'Paste the API token (input hidden)' -AsSecureString

    if (-not $IsWindows) {
        Write-Warning 'Not running on Windows: the token file will NOT be DPAPI-protected on this platform.'
    }

    $secure | Export-Clixml -Path $TokenPath
    Write-Host "Token stored at $TokenPath"
}

function Get-ApiToken {
    param([Parameter(Mandatory)] [string] $TokenPath)

    # Environment override is intended for development/testing only.
    if ($env:CROCKENHILL_API_TOKEN) {
        return $env:CROCKENHILL_API_TOKEN
    }

    if (-not (Test-Path $TokenPath)) {
        throw "No token found at '$TokenPath'. Run this script with -StoreToken first."
    }

    $secure = Import-Clixml -Path $TokenPath

    return [System.Net.NetworkCredential]::new('', $secure).Password
}

function Get-UploadState {
    param([Parameter(Mandatory)] [string] $StatePath)

    if (Test-Path $StatePath) {
        $raw = Get-Content $StatePath -Raw | ConvertFrom-Json

        # The leading comma stops PowerShell unrolling the list into the
        # pipeline (an empty list would otherwise arrive at the caller as $null).
        return , [System.Collections.Generic.List[object]] @($raw.uploaded)
    }

    return , [System.Collections.Generic.List[object]]::new()
}

function Save-UploadState {
    param(
        [Parameter(Mandatory)] [System.Collections.Generic.List[object]] $Uploaded,
        [Parameter(Mandatory)] [string] $StatePath
    )

    @{ uploaded = @($Uploaded) } | ConvertTo-Json -Depth 5 | Set-Content -Path $StatePath
}

function Test-FileIsStable {
    param(
        [Parameter(Mandatory)] [System.IO.FileInfo] $File,
        [Parameter(Mandatory)] [double] $MinAgeMinutes,
        [Parameter(Mandatory)] [int] $StabilitySeconds
    )

    if ($File.LastWriteTime -gt (Get-Date).AddMinutes(-$MinAgeMinutes)) {
        Write-Log "Skipping $($File.Name): modified less than $MinAgeMinutes minutes ago (still being written?)"

        return $false
    }

    $sizeBefore = $File.Length
    Start-Sleep -Seconds $StabilitySeconds
    $File.Refresh()

    if ($File.Length -ne $sizeBefore) {
        Write-Log "Skipping $($File.Name): size still changing ($sizeBefore -> $($File.Length) bytes)"

        return $false
    }

    # The writing application holds an exclusive write handle until finished.
    try {
        $stream = [System.IO.File]::Open($File.FullName, 'Open', 'Read', 'Read')
        $stream.Dispose()
    } catch {
        Write-Log "Skipping $($File.Name): file is still locked by another process"

        return $false
    }

    return $true
}

function Get-CurlBinary {
    # On Windows the real curl lives at curl.exe (bundled since Windows 10 1803).
    $name = $IsWindows ? 'curl.exe' : 'curl'
    $cmd = Get-Command $name -CommandType Application -ErrorAction SilentlyContinue

    if (-not $cmd) {
        throw "Could not find $name on PATH. It ships with Windows 10 1803+ and macOS."
    }

    return $cmd.Source
}

function Invoke-MultipartUpload {
    <#
    .SYNOPSIS
        POST a single file as multipart form-data via curl, which streams the
        body from disk (Invoke-RestMethod would buffer multi-GB files in memory).
        Returns the parsed JSON response body on the expected status code.
    #>
    param(
        [Parameter(Mandatory)] [string] $Url,
        [Parameter(Mandatory)] [System.IO.FileInfo] $File,
        [Parameter(Mandatory)] [string] $Token,
        [Parameter(Mandatory)] [string] $ExpectedStatus,
        [string] $ContentType = $null,
        [int] $RetryDelaySeconds = 90
    )

    $curl = Get-CurlBinary
    $responseFile = New-TemporaryFile
    $formValue = $ContentType ? "file=@$($File.FullName);type=$ContentType" : "file=@$($File.FullName)"

    try {
        $curlArgs = @(
            '--silent', '--show-error'
            '--request', 'POST'
            '--header', "Authorization: Bearer $Token"
            '--header', 'Accept: application/json'
            '--form', $formValue
            '--retry', '3'
            '--retry-delay', "$RetryDelaySeconds"
            '--max-time', '7200'
            '--write-out', '%{http_code}'
            '--output', $responseFile.FullName
            $Url
        )

        $httpCode = & $curl @curlArgs
        $body = Get-Content $responseFile.FullName -Raw -ErrorAction SilentlyContinue

        if ($LASTEXITCODE -ne 0) {
            throw "curl failed with exit code $LASTEXITCODE (HTTP $httpCode): $body"
        }

        if ($httpCode -ne $ExpectedStatus) {
            throw "Upload rejected with HTTP $httpCode`: $body"
        }

        return ($body | ConvertFrom-Json)
    } finally {
        Remove-Item $responseFile.FullName -ErrorAction SilentlyContinue
    }
}

Export-ModuleMember -Function @(
    'Resolve-ConfiguredPath'
    'Initialize-Log'
    'Write-Log'
    'Send-Notification'
    'Save-ApiToken'
    'Get-ApiToken'
    'Get-UploadState'
    'Save-UploadState'
    'Test-FileIsStable'
    'Get-CurlBinary'
    'Invoke-MultipartUpload'
)
