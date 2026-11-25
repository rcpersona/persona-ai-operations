param([Parameter(Mandatory=$true)][ValidateSet('read','connect','disconnect')][string]$Action)

$ErrorActionPreference = 'Stop'
$directory = Join-Path $env:LOCALAPPDATA 'RCPersonaConnector'
$file = Join-Path $directory 'credentials.dat'
$utf8 = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = $utf8
$OutputEncoding = $utf8

try {
    if ($Action -eq 'disconnect') {
        if (Test-Path -LiteralPath $file) { Remove-Item -LiteralPath $file }
        Write-Host 'Local RCPersona credentials removed.'
        exit 0
    }

    if ($Action -eq 'read') {
        $encrypted = [IO.File]::ReadAllText($file)
        $secure = ConvertTo-SecureString -String $encrypted
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        try { [Console]::Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)) }
        finally {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
            $secure.Dispose()
        }
        exit 0
    }

    $site = Read-Host 'HTTPS site URL [https://rcpersona.com]'
    if (!$site) { $site = 'https://rcpersona.com' }
    $login = Read-Host 'WordPress login'
    if (!$login -or $login.Contains(':')) { throw 'Enter a valid WordPress login.' }
    $password = Read-Host 'WordPress Application Password' -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
    try {
        $secret = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) -replace '\s', ''
        if ($secret -notmatch '^[A-Za-z0-9]{24}$') { throw 'Expected a 24-character WordPress Application Password.' }
        $payload = @{site=$site; login=$login; password=$secret} | ConvertTo-Json -Compress
        $profile = $payload | & node --use-system-ca (Join-Path $PSScriptRoot 'cli.mjs') verify
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

        $protected = ConvertTo-SecureString -String $payload -AsPlainText -Force
        try { $encrypted = ConvertFrom-SecureString -SecureString $protected }
        finally { $protected.Dispose() }

        [IO.Directory]::CreateDirectory($directory) | Out-Null
        $pending = Join-Path $directory ([Guid]::NewGuid().ToString() + '.dat')
        try {
            [IO.File]::WriteAllText($pending, $encrypted, $utf8)
            if ([IO.File]::Exists($file)) { [IO.File]::Replace($pending, $file, $null) }
            else { [IO.File]::Move($pending, $file) }
        } finally {
            if ([IO.File]::Exists($pending)) { [IO.File]::Delete($pending) }
        }
        Write-Output $profile
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
        $password.Dispose()
    }
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
