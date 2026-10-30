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

class RequireCommand extends InstallerCommand
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('require')
            ->setAliases(['r'])
            ->setDescription('add packages to composer.json and install them')
            ->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Packages to require, optionally with a constraint (e.g. vendor/package:^1.0)')
            ->addOption('no-update', null, InputOption::VALUE_NONE, 'Only update composer.json, do not resolve or install');
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
        $key = 'require';

        $requirements = $editor->resolveConstraints($editor->parseRequirements($input->getArgument('packages')));

        $composerBackup = $editor->backup();
        $lockBackup = file_exists('composer.lock') ? file_get_contents('composer.lock') : null;

        $output->writeln("<info>📝 Adding to $key in composer.json: " . implode(', ', array_map(
            fn ($name, $constraint) => "$name:$constraint",
            array_keys($requirements),
            $requirements
        )) . '</info>');
        $editor->addRequirements($requirements, $key);

        if ($input->getOption('no-update')) {
            return Command::SUCCESS;
        }

        try {
            $parser = new LockParser($packagist);
            [$composerJson, $packages] = $parser->update(array_keys($requirements));

            $this->installPackages($output, $parser, $composerJson, $packages, 'post-update-cmd');
        } catch (Throwable $e) {
            $output->writeln('<error>Installation failed, reverting composer.json and composer.lock to their original content.</error>');
            $editor->restore($composerBackup);
            if ($lockBackup !== null) {
                file_put_contents('composer.lock', $lockBackup);
            }

            throw $e;
        }

        $output->writeln('<info>✅ Pomposer require complete!</info>');

        return Command::SUCCESS;
    }
}
