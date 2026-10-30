<?php

namespace HichemTabTech\Pomposer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DumpAutoloadCommand extends InstallerCommand
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('dump-autoload')
            ->setAliases(['dumpautoload'])
            ->setDescription('regenerate the autoloader and package manifests for the installed packages')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Skip require-dev packages and autoload-dev')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Include require-dev packages and autoload-dev (default)')
            ->addOption('optimize', 'o', InputOption::VALUE_NONE, 'Accepted for Composer compatibility; the classmap is always generated');
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
        $this->configurePrompts($input, $output);

        if (!file_exists('composer.json')) {
            throw new RuntimeException('composer.json not found.');
        }

        // Use what is actually linked in vendor/, like Composer uses its installed repository.
        $installedPath = 'vendor/composer/installed.json';
        if (!file_exists($installedPath)) {
            throw new RuntimeException("$installedPath not found. Run `pomposer install` first.");
        }

        $composerJson = json_decode(file_get_contents('composer.json'), true);
        $installed = json_decode(file_get_contents($installedPath), true);

        $packages = $installed['packages'] ?? [];
        $devPackageNames = $installed['dev-package-names'] ?? [];
        $devMode = !$input->getOption('no-dev');

        if (!$devMode) {
            $packages = array_values(array_filter($packages, fn ($package) => !in_array($package['name'], $devPackageNames, true)));
            $devPackageNames = [];
        }

        $this->dumpAutoload($output, $composerJson, $packages, $devMode, $devPackageNames);

        $output->writeln('<info>✅ Generated autoload files containing ' . count($packages) . ' package(s)</info>');

        return Command::SUCCESS;
    }
}
