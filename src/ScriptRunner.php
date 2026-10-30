<?php

namespace HichemTabTech\Pomposer;

class ScriptRunner
{
    public function runScripts(array $composerJson, string $scriptKey, bool $devMode = true): void
    {
        if (!isset($composerJson['scripts'][$scriptKey])) {
            return;
        }

        // Like Composer, make vendor/bin available to scripts (e.g. "phpunit" in post-install-cmd).
        $binDir = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin';
        if (is_dir($binDir) && !str_starts_with((string) getenv('PATH'), $binDir . PATH_SEPARATOR)) {
            putenv('PATH=' . $binDir . PATH_SEPARATOR . getenv('PATH'));
        }
        putenv('COMPOSER_RUNTIME_BIN_DIR=' . $binDir);

        foreach ($composerJson['scripts'][$scriptKey] as $script) {
            if (str_starts_with($script, '@php ')) {
                $cmd = substr($script, 5);
                echo "[Pomposer] Running PHP script: php $cmd\n";
                passthru("php $cmd");
            } elseif (preg_match('/^([\\w\\\\]+)::([a-zA-Z_]\w*)$/', $script, $matches)) {
                // Static PHP handler, like Illuminate\Foundation\ComposerScripts::postAutoloadDump
                [, $class, $method] = $matches;
                echo "[Pomposer] Calling $class::$method() via separate process\n";

                // Run it through a small bootstrap that passes a stub Composer\Script\Event,
                // since handlers like Laravel's type-hint and use it.
                passthru(sprintf(
                    'php %s %s %s %s %s',
                    escapeshellarg(__DIR__ . '/stubs/run-script.php.stub'),
                    escapeshellarg($class),
                    escapeshellarg($method),
                    escapeshellarg($scriptKey),
                    $devMode ? '1' : '0'
                ));

            } elseif (str_starts_with($script, '@')) {
                // Aliases like @composer or @some-custom-script
                echo "[Pomposer] Skipping alias: $script (not supported yet)\n";
            } else {
                echo "[Pomposer] Executing shell script: $script\n";
                passthru($script);
            }
        }
    }
}