#Requires -Version 7.0
<#
.SYNOPSIS
    Assembles the next service in OpenLP from the website's canonical order of
    service (C2 in the service-automation report, client half of B1(b)).

.DESCRIPTION
    Intended to run from a desktop shortcut ("Build today's service") on the
    church PC, with OpenLP already open. Fetches the next upcoming service
    from GET /api/services/next, then drives OpenLP's local web-remote API
    (default http://localhost:4316) to build it: each song is searched in
    OpenLP's own library and appended to the service, so themes, formatting
    and verse order all behave natively.

    Only songs can be auto-added — OpenLP's API can only add items that
    already exist in a library, so readings, notices and other items are
    printed as an ordered checklist for the operator to insert by hand.
    The operator should always eyeball the result before the service.

.PARAMETER ConfigPath
    Path to the JSON config file. Defaults to config.json beside this script.
    Settings are read from the "openlp_assembly" section.

.PARAMETER Service
    Which service to fetch when the next date has more than one: morning
    (default), evening or other.

.PARAMETER KeepExisting
    Append to OpenLP's current service instead of starting a new one.

.PARAMETER StoreToken
    Prompt for the API token and store it encrypted (DPAPI on Windows) at the
    configured token_path, then exit. The token is shared with the upload
    scripts and needs the service:upload ability.

.PARAMETER DryRun
    Fetch and display the plan without touching OpenLP.

.EXAMPLE
    pwsh -File Build-OpenLpService.ps1
    pwsh -File Build-OpenLpService.ps1 -Service evening -DryRun
#>
[CmdletBinding()]
param(
    [string] $ConfigPath = (Join-Path $PSScriptRoot 'config.json'),
    [ValidateSet('morning', 'evening', 'other')] [string] $Service = 'morning',
    [switch] $KeepExisting,
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
$section = $config.openlp_assembly

$baseUrl = $config.base_url.TrimEnd('/')
$tokenPath = Resolve-ConfiguredPath $config.token_path -BaseDirectory $PSScriptRoot
$openLpUrl = $section.openlp_api_url.TrimEnd('/')
$openLpToken = $section.openlp_auth_token

Initialize-Log -Path (Resolve-ConfiguredPath $section.log_path -BaseDirectory $PSScriptRoot)

if ($StoreToken) {
    Save-ApiToken -TokenPath $tokenPath
    exit 0
}

# ---------------------------------------------------------------- OpenLP API

function Invoke-OpenLp {
    param(
        [Parameter(Mandatory)] [ValidateSet('GET', 'POST')] [string] $Method,
        [Parameter(Mandatory)] [string] $Path,
        [hashtable] $Body = $null
    )

    $headers = @{ Accept = 'application/json' }
    # OpenLP's optional authentication (Settings > API) expects the raw token
    # in the Authorization header, with no Bearer prefix.
    if ($openLpToken) {
        $headers.Authorization = $openLpToken
    }

    $arguments = @{
        Method = $Method
        Uri = "$openLpUrl$Path"
        Headers = $headers
        TimeoutSec = 15
    }

    if ($null -ne $Body) {
        $arguments.ContentType = 'application/json'
        $arguments.Body = ($Body | ConvertTo-Json)
    }

    $response = Invoke-WebRequest @arguments

    # ConvertFrom-Json -NoEnumerate plus the leading comma keep JSON arrays
    # intact through pipeline unrolling: a single-element result list like
    # [[101, "Title", ""]] must not collapse into its inner array.
    $parsed = $response.Content ? ($response.Content | ConvertFrom-Json -NoEnumerate) : $null

    return , $parsed
}

function Get-NormalisedTitle {
    param([AllowNull()] [AllowEmptyString()] [string] $Title)

    if (-not $Title) {
        return ''
    }

    # OpenLP search titles are lowercase with '@' separating the title from
    # the alternate title; display titles are neither. Reduce both to
    # lowercase alphanumerics + single spaces so they compare cleanly.
    $head = ($Title -split '@')[0]

    return (($head.ToLowerInvariant() -replace '[^a-z0-9]+', ' ').Trim())
}

function Find-OpenLpSong {
    param([Parameter(Mandatory)] [string] $SearchTitle)

    $normalised = Get-NormalisedTitle $SearchTitle
    $encoded = [uri]::EscapeDataString($normalised)
    $results = Invoke-OpenLp -Method GET -Path "/api/v2/plugins/songs/search?text=$encoded"

    if ($null -eq $results -or $results.Count -eq 0) {
        return $null
    }

    # Each result is [id, title, alternate_title].
    foreach ($result in $results) {
        if ((Get-NormalisedTitle $result[1]) -eq $normalised -or (Get-NormalisedTitle $result[2]) -eq $normalised) {
            return [pscustomobject]@{ Id = $result[0]; Title = $result[1]; Exact = $true }
        }
    }

    if ($results.Count -eq 1) {
        return [pscustomobject]@{ Id = $results[0][0]; Title = $results[0][1]; Exact = $false }
    }

    return $null
}

# ---------------------------------------------------------------- main

Write-Log "--- Build-OpenLpService run started ($Service) ---"

$token = Get-ApiToken -TokenPath $tokenPath
$headers = @{ Authorization = "Bearer $token"; Accept = 'application/json' }

try {
    $response = Invoke-RestMethod -Uri "$baseUrl/api/services/next?service=$Service" -Headers $headers -TimeoutSec 30
} catch {
    if ($_.Exception.Response.StatusCode -eq 404) {
        Write-Log "No upcoming $Service service found on the website." -Level ERROR
        exit 1
    }
    throw
}

$plan = $response.data
$items = @($plan.items | Sort-Object position)
Write-Log "Next service: $($plan.date) ($($plan.service)), $($items.Count) items$( $plan.needs_review ? ' [website review still pending]' : '' )"

if ($DryRun) {
    foreach ($item in $items) {
        Write-Log "[dry run] $($item.position). [$($item.type)] $($item.title)"
    }
    exit 0
}

# Confirm OpenLP is running before we do anything.
try {
    $null = Invoke-OpenLp -Method GET -Path '/api/v2/service/items'
} catch {
    Write-Log "Cannot reach OpenLP at $openLpUrl - is OpenLP running (with the API/web remote enabled)?" -Level ERROR
    exit 1
}

if (-not $KeepExisting) {
    $null = Invoke-OpenLp -Method GET -Path '/api/v2/service/new'
    Write-Log 'Started a new OpenLP service.'
}

$added = 0
$fuzzy = [System.Collections.Generic.List[string]]::new()
$manual = [System.Collections.Generic.List[string]]::new()

foreach ($item in $items) {
    if ($item.type -ne 'songs') {
        $manual.Add("$($item.position). [$($item.type)] $($item.title)")

        continue
    }

    $searchTitle = $item.openlp_search_title ? $item.openlp_search_title : $item.title
    $match = Find-OpenLpSong -SearchTitle $searchTitle

    if ($null -eq $match) {
        Write-Log "No confident OpenLP match for song '$($item.title)' (searched '$searchTitle')" -Level WARN
        $manual.Add("$($item.position). [song not found] $($item.title)")

        continue
    }

    $null = Invoke-OpenLp -Method POST -Path '/api/v2/plugins/songs/add' -Body @{ id = $match.Id }
    $added++

    if ($match.Exact) {
        Write-Log "Added song: $($match.Title)"
    } else {
        Write-Log "Added song (single loose match, please verify): '$($item.title)' -> '$($match.Title)'" -Level WARN
        $fuzzy.Add("$($item.position). '$($item.title)' -> '$($match.Title)'")
    }
}

Write-Log "Done: $added of $($items.Count) items added to OpenLP."

if ($fuzzy.Count -gt 0) {
    Write-Log 'Loose matches to verify:'
    $fuzzy | ForEach-Object { Write-Log "  $_" }
}

if ($manual.Count -gt 0) {
    Write-Log 'Add these by hand (in position order):'
    $manual | ForEach-Object { Write-Log "  $_" }
}

Send-Notification -Title 'OpenLP service built' -Body "$added songs added for $($plan.date) ($($plan.service)); $($manual.Count) items to add by hand."

exit 0
