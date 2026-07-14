param(
    [string]$ConfigPath = (Join-Path $PSScriptRoot '.env'),
    [string]$Distro = $null,
    [string]$Target = 'main'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path

function Get-ConfigValue {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,

        [Parameter(Mandatory = $true)]
        [string]$Name
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if (-not $trimmed -or $trimmed.StartsWith('#')) {
            continue
        }

        if ($trimmed -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$' -and $Matches[1] -eq $Name) {
            $value = $Matches[2]
            if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
                $value = $value.Substring(1, $value.Length - 2)
            }

            return $value
        }
    }

    return $null
}

if ([string]::IsNullOrWhiteSpace($Distro)) {
    $Distro = Get-ConfigValue -Path $ConfigPath -Name 'DEPLOY_WSL_DISTRO'
}

if ([string]::IsNullOrWhiteSpace($Distro)) {
    $Distro = $env:DEPLOY_WSL_DISTRO
}

if ([string]::IsNullOrWhiteSpace($Distro)) {
    $Distro = 'Ubuntu'
}

function Convert-ToWslPath {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    $result = & wsl.exe -d $Distro -- wslpath -a -u $Path 2>$null
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($result)) {
        throw "Unable to convert path for WSL: $Path"
    }

    return ($result | Out-String).Trim()
}

function Escape-BashSingleQuote {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Value
    )

    return "'" + ($Value -replace "'", "'\''") + "'"
}

$repoRootWsl = Convert-ToWslPath -Path $repoRoot
$scriptWsl = "$repoRootWsl/deploy/deploy-wsl.sh"

$scriptExists = & wsl.exe -d $Distro -- test -f $scriptWsl
if ($LASTEXITCODE -ne 0) {
    throw "WSL deploy script not found in distro ${Distro}: $scriptWsl"
}

$command = 'cd {0} && bash {1} {2}' -f (Escape-BashSingleQuote $repoRootWsl), (Escape-BashSingleQuote $scriptWsl), (Escape-BashSingleQuote $Target)

Write-Host "Running deployment through WSL distro: $Distro"
Write-Host "Log files will be written under logs/deploy"

& wsl.exe -d $Distro -- bash -lc $command
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
