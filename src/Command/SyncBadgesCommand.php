<?php

namespace App\Command;

use App\Gamification\BadgeSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gamification:sync-badges',
    description: 'Synchronise la table badge avec le catalogue du code (App\Gamification\BadgeCatalog) : upsert par code, suppression des badges obsolètes',
)]
class SyncBadgesCommand extends Command
{
    public function __construct(private readonly BadgeSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les changements sans les appliquer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->synchronizer->sync($dryRun);

        $rows = [];
        foreach (['inserted' => 'ajouté', 'updated' => 'mis à jour'] as $key => $label) {
            foreach ($result[$key] as $code) {
                $rows[] = [$code, $label];
            }
        }
        foreach ($result['deleted'] as $code => $owners) {
            $rows[] = [$code, sprintf('supprimé (%d attribution(s) retirée(s))', $owners)];
        }
        if ($rows) {
            $io->table(['Code', 'Action'], $rows);
        }

        $io->success(sprintf(
            '%s%d ajouté(s), %d mis à jour, %d inchangé(s), %d supprimé(s).',
            $dryRun ? '[simulation] ' : '',
            count($result['inserted']),
            count($result['updated']),
            count($result['unchanged']),
            count($result['deleted']),
        ));

        return Command::SUCCESS;
    }
}
