<?php

namespace App\Command;

use App\Account\InactiveAccountPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Daily scheduled task: warning e-mails and deletion of inactive accounts (App\Account\InactiveAccountPurger). */
#[AsCommand(
    name: 'app:accounts:purge-inactive',
    description: 'Prévient puis supprime les comptes inactifs (durées de config/packages/legal.yaml)',
)]
class PurgeInactiveAccountsCommand extends Command
{
    public function __construct(private readonly InactiveAccountPurger $purger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->purger->purge();
        (new SymfonyStyle($input, $output))->success(sprintf('%d compte(s) prévenu(s), %d compte(s) supprimé(s).', $result['warned'], $result['deleted']));
        return Command::SUCCESS;
    }
}
