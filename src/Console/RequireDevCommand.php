<?php

namespace HichemTabTech\Pomposer\Console;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Shortcut for `require --dev`.
 */
class RequireDevCommand extends RequireCommand
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('require-dev')
            ->setAliases(['rd'])
            ->setDescription('add packages to require-dev in composer.json and install them (same as require --dev)');
    }

    protected function isDev(InputInterface $input): bool
    {
        return true;
    }
}
