<?php

namespace HichemTabTech\Pomposer\Console;

use HichemTabTech\Pomposer\ScriptRunner;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunScriptCommand extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('run-script')
            ->setAliases(['run'])
            ->setDescription('run the scripts defined in composer.json')
            ->addArgument('script', InputArgument::OPTIONAL, 'Script name to run')
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Arguments passed to the script (put them after -- when they start with a dash)')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Sets script timeout in seconds, or 0 for never')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Sets the dev mode (default)')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Disables the dev mode')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List the scripts defined in composer.json');
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composerJson = self::readComposerJson();
        $script = $input->getArgument('script');

        if ($input->getOption('list')) {
            return $this->listScripts($output, $composerJson);
        }

        if ($script === null) {
            throw new RuntimeException('Missing required argument "script". Use --list to see the available scripts.');
        }

        return self::runScript($input, $output, $composerJson, $script, $input->getArgument('args'));
    }

    /**
     * Runs a script and turns a failing command into the script's exit code, like Composer.
     *
     * @param string[] $args
     */
    public static function runScript(InputInterface $input, OutputInterface $output, array $composerJson, string $script, array $args): int
    {
        $timeout = $input->getOption('timeout');
        if ($timeout !== null && !ctype_digit((string) $timeout)) {
            throw new RuntimeException('Timeout value must be numeric and positive if defined, or 0 for forever');
        }

        $runner = new ScriptRunner($composerJson, !$input->getOption('no-dev'), $timeout === null ? null : (int) $timeout);

        if (!$runner->hasScript($script)) {
            throw new RuntimeException('Script "' . $script . '" is not defined in this package');
        }

        try {
            $runner->run($script, $args);
        } catch (RuntimeException $e) {
            $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errorOutput->writeln('<error>' . $e->getMessage() . '</error>');

            return $e->getCode() > 0 ? $e->getCode() : Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    public static function readComposerJson(): array
    {
        if (!file_exists('composer.json')) {
            throw new RuntimeException('composer.json not found.');
        }

        $composerJson = json_decode(file_get_contents('composer.json'), true);
        if (!is_array($composerJson)) {
            throw new RuntimeException('composer.json is not valid JSON: ' . json_last_error_msg());
        }

        return $composerJson;
    }

    private function listScripts(OutputInterface $output, array $composerJson): int
    {
        $scripts = array_keys($composerJson['scripts'] ?? []);
        if ($scripts === []) {
            $output->writeln('<info>No scripts defined in composer.json</info>');

            return Command::SUCCESS;
        }

        $descriptions = $composerJson['scripts-descriptions'] ?? [];
        $width = max(array_map('strlen', $scripts));

        $output->writeln('<comment>scripts:</comment>');
        foreach ($scripts as $name) {
            $description = $descriptions[$name] ?? 'Runs the ' . $name . ' script as defined in composer.json';
            $output->writeln(sprintf('  <info>%s</info>  %s', str_pad($name, $width), $description));
        }

        return Command::SUCCESS;
    }
}
