<?php

namespace App\Command;

use App\Moderation\ModerationRecordPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Daily scheduled task: retention of reports and appeals (App\Moderation\ModerationRecordPurger). */
#[AsCommand(
    name: 'app:moderation:purge',
    description: 'Supprime les signalements et réclamations dont la durée de conservation est écoulée',
)]
class PurgeModerationRecordsCommand extends Command
{
    public function __construct(private readonly ModerationRecordPurger $purger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->purger->purge();
        (new SymfonyStyle($input, $output))->success(sprintf('%d signalement(s) et %d réclamation(s) supprimé(s).', $result['reports'], $result['appeals']));
        return Command::SUCCESS;
    }
}
