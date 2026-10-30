<?php

namespace HichemTabTech\Pomposer;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the scripts defined in composer.json, like Composer's EventDispatcher:
 * @php, @composer, @putenv and @<script> references, Class::method handlers and shell commands.
 */
class ScriptRunner
{
    /**
     * Composer events: they can be run with run-script but are not exposed as top-level commands.
     */
    public const EVENTS = [
        'pre-install-cmd', 'post-install-cmd', 'pre-update-cmd', 'post-update-cmd',
        'pre-status-cmd', 'post-status-cmd', 'pre-archive-cmd', 'post-archive-cmd',
        'pre-autoload-dump', 'post-autoload-dump', 'post-root-package-install', 'post-create-project-cmd',
        'pre-operations-exec', 'pre-package-install', 'post-package-install', 'pre-package-update',
        'post-package-update', 'pre-package-uninstall', 'post-package-uninstall',
        'init', 'command', 'pre-file-download', 'post-file-download', 'pre-command-run', 'pre-pool-create',
    ];

    private const DEFAULT_TIMEOUT = 300;

    /** @var string[] Scripts currently running, to detect @script recursion. */
    private array $stack = [];

    private ?float $timeout;

    /**
     * @param int|null $timeout Process timeout in seconds (0 = none); defaults to config.process-timeout, like Composer.
     */
    public function __construct(private readonly array $composerJson, private readonly bool $devMode = true, ?int $timeout = null)
    {
        $timeout ??= getenv('COMPOSER_PROCESS_TIMEOUT') !== false
            ? (int) getenv('COMPOSER_PROCESS_TIMEOUT')
            : (int) ($composerJson['config']['process-timeout'] ?? self::DEFAULT_TIMEOUT);

        $this->timeout = $timeout > 0 ? (float) $timeout : null;
    }

    public function hasScript(string $name): bool
    {
        return isset($this->composerJson['scripts'][$name]);
    }

    /**
     * Runs every command of the given script / event.
     *
     * @param string[] $args Extra arguments, appended to commands (or put in place of @additional_args).
     * @throws RuntimeException when a command fails; the exit code is the exception code.
     */
    public function run(string $name, array $args = []): void
    {
        if (!$this->hasScript($name)) {
            return;
        }

        if (in_array($name, $this->stack, true)) {
            throw new RuntimeException('Circular call to script handler "' . $name . '" detected (' . implode(' -> ', [...$this->stack, $name]) . ')');
        }

        $this->prepareEnvironment();
        $this->stack[] = $name;

        try {
            foreach ((array) $this->composerJson['scripts'][$name] as $script) {
                $this->runOne($name, (string) $script, $args);
            }
        } finally {
            array_pop($this->stack);
        }
    }

    private function runOne(string $name, string $script, array $args): void
    {
        if ($script === 'Composer\\Config::disableProcessTimeout') {
            $this->timeout = null;

            return;
        }

        if (str_starts_with($script, '@putenv ')) {
            $this->putenv(substr($script, 8));

            return;
        }

        if (str_starts_with($script, '@') && !str_starts_with($script, '@php ') && !str_starts_with($script, '@composer ')) {
            // Reference to another script: "@test" or "@test --filter=foo".
            $parts = preg_split('/\s+/', substr($script, 1), 2);
            $reference = $parts[0];

            if (!$this->hasScript($reference)) {
                throw new RuntimeException('You made a reference to a non-existent script @' . $reference);
            }

            $this->run($reference, isset($parts[1]) ? [...$this->splitArgs($parts[1]), ...$args] : $args);

            return;
        }

        if ($this->isPhpHandler($script)) {
            [$class, $method] = explode('::', $script, 2);

            $this->output("> $script");
            $this->execute(
                [$this->phpBinary(), __DIR__ . '/stubs/run-script.php.stub', $class, $method, $name, $this->devMode ? '1' : '0', ...$args],
                $script,
                $name
            );

            return;
        }

        $command = match (true) {
            str_starts_with($script, '@php ') => $this->escape($this->phpBinary()) . ' ' . substr($script, 5),
            str_starts_with($script, '@composer ') => $this->escape($this->phpBinary()) . ' ' . $this->escape($this->pomposerBinary()) . ' ' . substr($script, 10),
            default => $script,
        };

        $extra = implode(' ', array_map($this->escape(...), $args));
        $command = str_contains($command, '@additional_args')
            ? str_replace('@additional_args', $extra, $command)
            : rtrim($command . ' ' . $extra);

        $this->output("> $command");
        $this->execute($command, $script, $name);
    }

    /**
     * @param string|string[] $command
     */
    private function execute(string|array $command, string $script, string $name): void
    {
        $process = is_array($command) ? new Process($command) : Process::fromShellCommandline($command);
        $process->setTimeout($this->timeout);

        // Keep scripts interactive (colors, prompts) when we are attached to a terminal.
        $tty = Process::isTtySupported() && stream_isatty(STDIN) && stream_isatty(STDOUT);
        $process->setTty($tty);

        try {
            $exitCode = $tty
                ? $process->run()
                : $process->run(function (string $type, string $buffer): void {
                    fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
                });
        } catch (ProcessTimedOutException $e) {
            throw new RuntimeException('Script ' . $script . ' handling the ' . $name . ' event timed out after ' . (int) $this->timeout . ' seconds. Use --timeout=0 or "config": {"process-timeout": 0} to disable the timeout.', 1, $e);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException('Script ' . $script . ' handling the ' . $name . ' event returned with error code ' . $exitCode, $exitCode);
        }
    }

    private function prepareEnvironment(): void
    {
        // Like Composer, make vendor/bin available to scripts (e.g. "phpunit" in post-install-cmd).
        $binDir = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin';
        if (is_dir($binDir) && !str_starts_with((string) getenv('PATH'), $binDir . PATH_SEPARATOR)) {
            $this->setEnv('PATH', $binDir . PATH_SEPARATOR . getenv('PATH'));
        }

        $this->setEnv('COMPOSER_RUNTIME_BIN_DIR', $binDir);
        $this->setEnv('COMPOSER_DEV_MODE', $this->devMode ? '1' : '0');
        $this->setEnv('PHP_BINARY', $this->phpBinary());
    }

    /**
     * "@putenv NAME=value" sets a variable, "@putenv NAME" removes it.
     */
    private function putenv(string $definition): void
    {
        if (str_contains($definition, '=')) {
            [$key, $value] = explode('=', $definition, 2);
            $this->setEnv(trim($key), $value);
        } else {
            $this->setEnv(trim($definition), null);
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        // Symfony Process inherits from $_SERVER / $_ENV / getenv(), so keep all three in sync.
        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            putenv("$key=$value");
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }

    private function isPhpHandler(string $script): bool
    {
        return !str_contains($script, ' ') && preg_match('/^[\w\\\\]+::[a-zA-Z_]\w*$/', $script) === 1;
    }

    private function splitArgs(string $args): array
    {
        return array_values(array_filter(str_getcsv($args, ' ', '"', ''), fn ($part) => (string) $part !== ''));
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
    }

    private function pomposerBinary(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pomposer';
    }

    private function escape(string $argument): string
    {
        return (new Process([$argument]))->getCommandLine();
    }

    private function output(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
