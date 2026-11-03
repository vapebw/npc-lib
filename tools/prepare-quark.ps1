param(
    [Parameter(Mandatory=$true)][string]$RuntimeArchive,
    [Parameter(Mandatory=$true)][string]$ComposerPhar
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$quark = Join-Path $root 'work/quark'
$runtime = Join-Path $root 'work/quark-runtime'
$evidence = Join-Path $root 'work/integration-setup'
$expected = '0f91b13d470026cf6aefb5df649d32db9b0e342d'
$archiveHash = '0b09107d778db4de17e95e8c96dcd525d0a67b9c9d1fe7dbad2e3dbddb67d757'
if ((Get-FileHash -LiteralPath $RuntimeArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $archiveHash) {
    throw 'Runtime archive checksum mismatch'
}
if (!(Test-Path -LiteralPath "$quark/.git")) {
    & git clone --no-checkout https://github.com/Bedrock-Phanatics/Quark.git $quark
    if ($LASTEXITCODE -ne 0) { throw 'Quark clone failed' }
    & git -C $quark checkout --detach $expected
    if ($LASTEXITCODE -ne 0) { throw 'Quark checkout failed' }
}
if ((& git -C $quark rev-parse HEAD) -ne $expected) { throw 'Unexpected Quark revision' }
& git -C $quark diff --exit-code HEAD
if ($LASTEXITCODE -ne 0) { throw 'Quark has tracked changes' }
New-Item -ItemType Directory -Force $evidence | Out-Null
Expand-Archive -LiteralPath $RuntimeArchive -DestinationPath $runtime -Force
$bin = Join-Path $runtime 'bin/php'
$php = Join-Path $bin 'php.exe'
$manifest = Join-Path $quark 'npc-lib-deployment.json'
$lock = Join-Path $quark 'npc-lib-deployment.lock'
if ((Test-Path -LiteralPath $manifest) -or (Test-Path -LiteralPath $lock)) {
    throw 'Deployment manifest already exists; inspect it before retrying'
}
$saved = @{}
foreach ($key in @('COMPOSER','COMPOSER_ROOT_VERSION','PHP_INI_SCAN_DIR','OPENSSL_CONF','OPENSSL_MODULES')) {
    $saved[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
}
try {
    $env:PHP_INI_SCAN_DIR = ''
    $env:OPENSSL_CONF = "$bin/extras/ssl/openssl.cnf"
    $env:OPENSSL_MODULES = "$bin/extras/ssl"
    Remove-Item Env:COMPOSER_ROOT_VERSION -ErrorAction SilentlyContinue
    $configuration = Get-Content -LiteralPath "$quark/composer.json" -Raw | ConvertFrom-Json
    $configuration.name = 'quark/quark'
    $configuration | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $manifest -Encoding utf8NoBOM
    Copy-Item -LiteralPath "$quark/composer.lock" -Destination $lock
    $env:COMPOSER = 'npc-lib-deployment.json'
    & $php -c "$bin/php.ini" -d "extension_dir=$bin/ext" $ComposerPhar --working-dir=$quark update --lock --no-dev --no-interaction --classmap-authoritative *> "$evidence/composer.log"
    if ($LASTEXITCODE -ne 0) { throw 'Deployment dependency installation failed' }
    $original = Get-Content -LiteralPath "$quark/composer.lock" -Raw | ConvertFrom-Json
    $deployed = Get-Content -LiteralPath $lock -Raw | ConvertFrom-Json
    foreach ($key in @('packages','packages-dev')) {
        if (($original.$key | ConvertTo-Json -Depth 100 -Compress) -cne ($deployed.$key | ConvertTo-Json -Depth 100 -Compress)) {
            throw 'Deployment changed locked dependencies'
        }
    }
    Copy-Item -LiteralPath $manifest -Destination "$evidence/npc-lib-deployment.json"
    Copy-Item -LiteralPath $lock -Destination "$evidence/npc-lib-deployment.lock"
    & $php -c "$bin/php.ini" -d "extension_dir=$bin/ext" "$quark/src/Quark.php" --version *> "$evidence/version.log"
    if ($LASTEXITCODE -ne 0) { throw 'Quark platform or revision check failed' }
    Get-Content -LiteralPath "$evidence/version.log"
} finally {
    foreach ($key in $saved.Keys) { [Environment]::SetEnvironmentVariable($key, $saved[$key], 'Process') }
    Remove-Item -LiteralPath $manifest,$lock -ErrorAction SilentlyContinue
}
$status = (& git -C $quark status --porcelain | Out-String)
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect Quark status' }
Set-Content -LiteralPath "$evidence/quark-status.log" -Value $status
if ($status.Trim() -ne '') { throw 'Quark tree is not clean' }
