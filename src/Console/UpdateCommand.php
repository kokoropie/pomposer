<?php

namespace HichemTabTech\Pomposer\Console;

use HichemTabTech\Pomposer\LockParser;
use HichemTabTech\Pomposer\PackagistGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends InstallerCommand
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('update')
            ->setAliases(['u', 'upgrade'])
            ->setDescription('re-resolve dependencies from composer.json and update composer.lock')
            ->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages to update (default: all)');
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

        $only = array_map('strtolower', $input->getArgument('packages'));

        $output->writeln($only
            ? '<info>🔍 Updating ' . implode(', ', $only) . '...</info>'
            : '<info>🔍 Updating all dependencies...</info>');

        $parser = new LockParser(new PackagistGateway());
        [$composerJson, $packages] = $parser->update($only);

        $this->installPackages($output, $parser, $composerJson, $packages, 'post-update-cmd');

        $output->writeln('<info>✅ Pomposer update complete!</info>');

        return Command::SUCCESS;
    }
}
