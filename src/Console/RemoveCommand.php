<?php

namespace HichemTabTech\Pomposer\Console;

use HichemTabTech\Pomposer\ComposerJsonEditor;
use HichemTabTech\Pomposer\LockParser;
use HichemTabTech\Pomposer\PackagistGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class RemoveCommand extends InstallerCommand
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('remove')
            ->setAliases(['rm', 'uninstall'])
            ->setDescription('remove packages from composer.json and uninstall them')
            ->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Packages to remove')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Accepted for Composer compatibility; packages are removed from both require and require-dev')
            ->addOption('no-update', null, InputOption::VALUE_NONE, 'Only update composer.json, do not resolve or install')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Skip installing require-dev packages and autoload-dev (they are still locked)');
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

        $packagist = new PackagistGateway();
        $editor = new ComposerJsonEditor($packagist);
        $names = array_map('strtolower', $input->getArgument('packages'));

        $composerBackup = $editor->backup();
        $lockBackup = file_exists('composer.lock') ? file_get_contents('composer.lock') : null;

        $removed = $editor->removeRequirements($names);

        foreach (array_diff($names, $removed) as $name) {
            $output->writeln("<comment>⚠️  $name is not required in composer.json and has not been removed</comment>");
        }

        if (!$removed) {
            return Command::FAILURE;
        }

        $output->writeln('<info>📝 Removed from composer.json: ' . implode(', ', $removed) . '</info>');

        if ($input->getOption('no-update')) {
            return Command::SUCCESS;
        }

        try {
            // Keep everything else locked; the removed packages and dependencies nothing needs anymore drop out.
            $parser = new LockParser($packagist);
            [$composerJson, $packages] = $parser->update($removed);

            $this->installPackages($output, $parser, $composerJson, $packages, 'post-update-cmd', !$input->getOption('no-dev'));
        } catch (Throwable $e) {
            $output->writeln('<error>Removal failed, reverting composer.json and composer.lock to their original content.</error>');
            $editor->restore($composerBackup);
            if ($lockBackup !== null) {
                file_put_contents('composer.lock', $lockBackup);
            }

            throw $e;
        }

        $output->writeln('<info>✅ Pomposer remove complete!</info>');

        return Command::SUCCESS;
    }
}
