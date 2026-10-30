<?php

namespace HichemTabTech\Pomposer\Console;

use HichemTabTech\Pomposer\ScriptRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Exposes a custom composer.json script as a top-level command (`pomposer test`), like Composer.
 */
class ScriptAliasCommand extends Command
{
    public function __construct(private readonly string $script, ?string $description, array $aliases = [])
    {
        parent::__construct($script);

        $this
            ->setDescription($description ?? 'Runs the ' . $script . ' script as defined in composer.json')
            ->setAliases($aliases);
    }

    /**
     * Registers every custom script of ./composer.json that does not clash with a command or a Composer event.
     */
    public static function registerAll(Application $app): void
    {
        try {
            $composerJson = RunScriptCommand::readComposerJson();
        } catch (Throwable) {
            return;
        }

        $descriptions = $composerJson['scripts-descriptions'] ?? [];
        $aliases = $composerJson['scripts-aliases'] ?? [];

        foreach (array_keys($composerJson['scripts'] ?? []) as $script) {
            $script = (string) $script;
            if (in_array($script, ScriptRunner::EVENTS, true) || $app->has($script)) {
                continue;
            }

            try {
                $scriptAliases = array_values(array_filter((array) ($aliases[$script] ?? []), fn ($alias) => !$app->has($alias)));
                $app->add(new self($script, $descriptions[$script] ?? null, $scriptAliases));
            } catch (Throwable) {
                // Not a valid command name; still reachable through run-script.
            }
        }
    }

    protected function configure(): void
    {
        $this->ignoreValidationErrors();

        $this
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Arguments passed to the script')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Sets script timeout in seconds, or 0 for never')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Sets the dev mode (default)')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Disables the dev mode');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Validation errors are ignored, so unknown options (e.g. `pomposer test --filter=foo`) are
        // forwarded to the script as typed instead of being dropped.
        $args = $input instanceof ArgvInput && method_exists($input, 'getRawTokens')
            ? array_values(array_filter(
                $input->getRawTokens(true),
                fn (string $token) => !in_array($token, ['--dev', '--no-dev'], true) && !str_starts_with($token, '--timeout')
            ))
            : $input->getArgument('args');

        return RunScriptCommand::runScript($input, $output, RunScriptCommand::readComposerJson(), $this->script, $args);
    }
}
