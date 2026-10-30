<?php

namespace HichemTabTech\Pomposer\Console;

use HichemTabTech\Pomposer\AutoloadGenerator;
use HichemTabTech\Pomposer\Concerns\CommandsUtils;
use HichemTabTech\Pomposer\Concerns\ConfiguresPrompts;
use HichemTabTech\Pomposer\LockFile;
use HichemTabTech\Pomposer\LockParser;
use HichemTabTech\Pomposer\PackageInstaller;
use HichemTabTech\Pomposer\PackageStore;
use HichemTabTech\Pomposer\ScriptRunner;
use HichemTabTech\Pomposer\VendorLinker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared install pipeline for install/update/require: download to the store,
 * write composer.lock, link vendor/, generate autoload + manifests, run scripts.
 */
abstract class InstallerCommand extends Command
{
    use ConfiguresPrompts;
    use CommandsUtils;

    /**
     * Interact with the user before validating the input.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write(PHP_EOL.'  <fg=red>
         _____                                          
        |  __ \                                         
        | |__) |__  _ __ ___  _ __   ___  ___  ___ _ __ 
        |  ___/ _ \| \'_ ` _ \| \'_ \ / _ \/ __|/ _ \ \'__|
        | |  | (_) | | | | | | |_) | (_) \__ \  __/ |
        |_|   \___/|_| |_| |_| .__/ \___/|___/\___|_|
                             | |
                             |_|                                                                            
        </>'.PHP_EOL.PHP_EOL);

        $output->writeln('<fg=yellow>⚠️ Pomposer is in beta. Use at your own risk! Not for production use. It was built just as a proof of concept for now.</>');

        $output->writeln('<fg=blue>💡 Interested in helping Pomposer grow?</>');
        $output->writeln('<fg=blue>👉 If you have ideas, experience, or just curiosity — please join the discussion and contribute at: https://github.com/HichemTab-tech/pomposer/discussions/4</>');
        $output->writeln('<fg=cyan>https://github.com/HichemTab-tech/pomposer</>' . PHP_EOL);

    }

    /**
     * @param string $scriptEvent The Composer script event to run after the autoload dump (e.g. post-install-cmd).
     * @param bool $devMode Install require-dev packages and root autoload-dev (false = --no-dev).
     */
    protected function installPackages(OutputInterface $output, LockParser $parser, array $composerJson, array $packages, string $scriptEvent, bool $devMode = true): void
    {
        // composer.lock always keeps the dev packages; --no-dev only skips installing them.
        $lockPackages = $packages;
        $devPackageNames = $parser->getDevPackageNames();

        if (!$devMode) {
            $packages = array_values(array_filter($packages, fn ($package) => !in_array($package['name'], $devPackageNames, true)));
            $devPackageNames = [];
            $output->writeln('<info>🚫 Skipping ' . (count($lockPackages) - count($packages)) . ' dev package(s) (--no-dev)</info>');
        }

        $store = new PackageStore();
        $installer = new PackageInstaller($store);

        foreach ($packages as $pkg) {
            $installer->install($pkg);
        }

        if ($parser->wasResolved()) {
            $output->writeln('<info>🔒 Writing composer.lock...</info>');
            (new LockFile())->write($composerJson, ...$parser->getResolvedPackages());
        }

        $output->writeln('<info>🔗 Linking packages into vendor/...</info>');
        (new VendorLinker($store))->link($packages);

        $this->dumpAutoload($output, $composerJson, $packages, $devMode, $devPackageNames);

        $scriptRunner = new ScriptRunner();
        $scriptRunner->runScripts($composerJson, $scriptEvent, $devMode);

        $output->writeln('<info>✅ Post-install scripts completed!</info>');
    }

    /**
     * Generates the autoloader and package manifests, then runs post-autoload-dump.
     *
     * @param string[] $devPackageNames Installed packages only needed by require-dev.
     */
    protected function dumpAutoload(OutputInterface $output, array $composerJson, array $packages, bool $devMode, array $devPackageNames): void
    {
        $generator = new AutoloadGenerator();

        $output->writeln('<info>⚙️ Generating autoload files...</info>');
        $generator->generate($packages, $composerJson, $devMode);

        $output->writeln('<info>📦 Generating full package manifest set...</info>');
        // Pass both the packages and the root composer.json
        $generator->generatePackageManifests($packages, $composerJson, $devMode, $devPackageNames);

        $output->writeln('<info>🔄 Running post-autoload-dump scripts...</info>');
        (new ScriptRunner())->runScripts($composerJson, 'post-autoload-dump', $devMode);
    }
}
