<?php

namespace HichemTabTech\Pomposer\Console;

use HichemTabTech\Pomposer\LockParser;
use HichemTabTech\Pomposer\PackagistGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends InstallerCommand
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('install composer packages')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Skip require-dev packages and autoload-dev');
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

        $output->writeln('<info>🔍 Reading composer.lock...</info>');

        $parser = new LockParser(new PackagistGateway());
        [$composerJson, $packages] = $parser->getPackages();

        $this->installPackages($output, $parser, $composerJson, $packages, 'post-install-cmd', !$input->getOption('no-dev'));

        $output->writeln('<info>✅ Pomposer install complete!</info>');

        return Command::SUCCESS;
    }
}
