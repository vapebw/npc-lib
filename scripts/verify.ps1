param(
    [string]$Composer = $env:NPCLIB_COMPOSER,
    [string]$Php85 = $env:NPCLIB_PHP85
)

$ErrorActionPreference = 'Stop'
if(-not $Composer -or -not (Test-Path $Composer)){ throw 'Set -Composer or NPCLIB_COMPOSER to composer.phar' }
if(-not $Php85 -or -not (Test-Path $Php85)){ throw 'Set -Php85 or NPCLIB_PHP85 to a PHP 8.5 php.exe' }

$env:PHP_INI_SCAN_DIR = ''
$env:COMPOSER_ROOT_VERSION = '0.0.0-dev'
$bin84 = (Resolve-Path work/quark-runtime/bin/php).Path
$php84 = "$bin84/php.exe"
$env:OPENSSL_CONF = "$bin84/extras/ssl/openssl.cnf"
$env:OPENSSL_MODULES = "$bin84/extras/ssl"
New-Item -ItemType Directory -Force work/logs | Out-Null
$results = [System.Collections.Generic.List[object]]::new()

function Check([string]$name, [string]$exe, [string[]]$arguments){
    & $exe @arguments *> "work/logs/$name.log"
    $code = $LASTEXITCODE
    $results.Add([pscustomobject]@{ name = $name; exit = $code; executable = $exe; arguments = $arguments })
    $results | ConvertTo-Json -Depth 6 | Set-Content work/logs/checks.json
    Write-Output "$name exit=$code"
    if($code -ne 0){ Get-Content "work/logs/$name.log" -Tail 50; throw "Failed $name" }
}

$prefix84 = @('-c', "$bin84/php.ini", '-d', "extension_dir=$bin84/ext")
$harness = '--working-dir=tests/harness'
Check 'install' $php84 ($prefix84 + @($Composer, 'install', '--no-interaction'))
Check 'stage' $php84 ($prefix84 + @($Composer, 'stage:package'))
Check 'harness-refresh' $php84 ($prefix84 + @($Composer, $harness, 'reinstall', 'vapebw/npc-lib', '--no-interaction', '--classmap-authoritative'))
Check 'harness-validate' $php84 ($prefix84 + @($Composer, $harness, 'validate', '--strict'))
Check 'harness-platform' $php84 ($prefix84 + @($Composer, $harness, 'check-platform-reqs', '--no-dev'))
Check 'harness-psr4' $php84 ($prefix84 + @($Composer, $harness, 'dump-autoload', '--no-dev', '--classmap-authoritative', '--strict-psr'))
$bench = '--working-dir=bench/plugin'
Check 'bench-refresh' $php84 ($prefix84 + @($Composer, $bench, 'reinstall', 'vapebw/npc-lib', '--no-interaction', '--classmap-authoritative'))
Check 'bench-validate' $php84 ($prefix84 + @($Composer, $bench, 'validate', '--strict'))
Check 'bench-psr4' $php84 ($prefix84 + @($Composer, $bench, 'dump-autoload', '--no-dev', '--classmap-authoritative', '--strict-psr'))
Check 'build' $php84 ($prefix84 + @($Composer, 'build:harness'))
Check 'build-bench' $php84 ($prefix84 + @($Composer, 'build:bench'))
Check 'quality-84' $php84 ($prefix84 + @($Composer, 'check'))
Check 'phar-84' $php84 ($prefix84 + @($Composer, 'test:phar'))
Check 'analyse-harness-84' $php84 ($prefix84 + @($Composer, 'analyse:harness'))
Check 'quality-85' $Php85 @('-c', 'work/php85.ini', $Composer, 'check')
Check 'phar-85' $Php85 @('-c', 'work/php85.ini', $Composer, 'test:phar')
Check 'analyse-harness-85' $Php85 @('-c', 'work/php85.ini', $Composer, 'analyse:harness')
Check 'quark-platform' $php84 ($prefix84 + @($Composer, '--working-dir=work/quark', 'check-platform-reqs', '--no-dev'))
Check 'quark-version' $php84 ($prefix84 + @('work/quark/src/Quark.php', '--version'))
Check 'python-syntax' 'python' @('-c', "from pathlib import Path; [compile(Path(p).read_text(encoding='utf8'), p, 'exec') for p in ['scripts/run-quark.py', 'scripts/run-wire-server.py', 'scripts/run-bedrock.py', 'scripts/quarkserver.py', 'bench/runner/cli.py', 'bench/runner/server.py', 'bench/runner/counters.py', 'bench/runner/viewers.py', 'bench/runner/report.py', 'bench/runner/tables.py', 'bench/runner/stats.py', 'bench/runner/schedule.py', 'bench/runner/experiments.py', 'bench/runner/validate.py']]; print('PASS')")
Check 'client-syntax' 'node' @('--check', 'tests/bedrock/client.cjs')
Check 'driver-syntax' 'node' @('--check', 'tests/bedrock/run-suite.cjs')
Check 'viewers-syntax' 'node' @('--check', 'bench/viewers/viewers.cjs')
Check 'client-serializer' 'node' @('tests/bedrock/client.cjs', '--verify')

$status = (& git -C work/quark status --porcelain | Out-String)
if($LASTEXITCODE -ne 0 -or $status.Trim() -ne ''){ throw 'Quark source is not clean' }
Set-Content -LiteralPath work/logs/quark-status.log -Value $status
