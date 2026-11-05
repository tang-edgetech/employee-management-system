#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Applies the Meridian Workforce dev changes to XAMPP (milestone M0).

.DESCRIPTION
    - php.ini:            enables extension=gd, intl, sodium, zip
    - httpd.conf:         enables mod_proxy_http and mod_proxy_wstunnel
    - httpd-vhosts.conf:  includes tools/xampp/meridian-vhost.conf
    - mysql\bin\my.ini:   MariaDB listens on 127.0.0.1 only; max_allowed_packet 64M;
                          innodb_buffer_pool_size 512M. Strict mode, UTC and the
                          collation are set per connection by Laravel, not server-wide,
                          so other databases on this XAMPP are unaffected.

    Safe to run more than once. Each file is backed up as <file>.bak-<yyyyMMdd>
    before its first change that day. If Apache's config test fails afterwards,
    all three files are restored from those backups.

    Run from an elevated PowerShell, then restart Apache and MySQL from the XAMPP Control Panel.
#>
param(
    [string]$Xampp = 'C:\xampp'
)

$ErrorActionPreference = 'Stop'
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$stamp = Get-Date -Format 'yyyyMMdd'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$touched = @()

function Update-ConfigFile {
    param([string]$Path, [scriptblock]$Change)

    $text = [IO.File]::ReadAllText($Path)
    $new = & $Change $text
    if ($new -ceq $text) {
        Write-Host "unchanged  $Path"
        return
    }
    $backup = "$Path.bak-$stamp"
    if (-not (Test-Path $backup)) {
        Copy-Item $Path $backup
    }
    [IO.File]::WriteAllText($Path, $new, $utf8NoBom)
    $script:touched += $Path
    Write-Host "updated    $Path  (backup: $backup)"
}

$phpIni = Join-Path $Xampp 'php\php.ini'
$httpdConf = Join-Path $Xampp 'apache\conf\httpd.conf'
$vhostsConf = Join-Path $Xampp 'apache\conf\extra\httpd-vhosts.conf'
$mariadbIni = Join-Path $Xampp 'mysql\bin\my.ini'

Update-ConfigFile $phpIni {
    param($t)
    foreach ($ext in 'gd', 'intl', 'sodium', 'zip') {
        $t = $t -replace "(?m)^;extension=$ext(?=\r?$)", "extension=$ext"
    }
    $t
}

Update-ConfigFile $httpdConf {
    param($t)
    foreach ($mod in 'http', 'wstunnel') {
        $t = $t -replace "(?m)^#(LoadModule proxy_$($mod)_module modules/mod_proxy_$mod\.so)(?=\r?$)", '$1'
    }
    $t
}

$includeLine = 'Include "' + ($repo -replace '\\', '/') + '/tools/xampp/meridian-vhost.conf"'
Update-ConfigFile $vhostsConf {
    param($t)
    if ($t -match [regex]::Escape('/tools/xampp/meridian-vhost.conf')) { return $t }
    $t.TrimEnd() + "`r`n`r`n# Meridian Workforce dev site (added by tools/xampp/apply-config.ps1)`r`n$includeLine`r`n"
}

# Only the [mysqld] values XAMPP ships with are replaced; [mysqldump] keeps its own 16M packet size.
Update-ConfigFile $mariadbIni {
    param($t)
    $t = $t -replace '(?m)^#[ \t]*bind-address[ \t]*=[ \t]*"127\.0\.0\.1"[ \t]*(?=\r?$)', 'bind-address="127.0.0.1"'
    $t = $t -replace '(?m)^max_allowed_packet=1M(?=\r?$)', 'max_allowed_packet=64M'
    $t = $t -replace '(?m)^innodb_buffer_pool_size=16M(?=\r?$)', 'innodb_buffer_pool_size=512M'
    $t
}

Write-Host ''
Write-Host 'Checking Apache config...'
& (Join-Path $Xampp 'apache\bin\httpd.exe') -t
if ($LASTEXITCODE -ne 0) {
    foreach ($path in $touched) {
        Copy-Item "$path.bak-$stamp" $path -Force
        Write-Host "restored   $path"
    }
    throw 'Apache config test failed; changes were rolled back. See the error above.'
}

Write-Host ''
Write-Host 'Checking PHP extensions...'
$loaded = & (Join-Path $Xampp 'php\php.exe') -m
foreach ($ext in 'gd', 'intl', 'sodium', 'zip') {
    if ($loaded -contains $ext) { Write-Host "  ok       $ext" } else { Write-Warning "  missing  $ext" }
}

Write-Host ''
Write-Host 'Done. Restart Apache and MySQL from the XAMPP Control Panel to apply.'
