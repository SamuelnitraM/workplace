<?php

namespace App\Command;

use App\Service\BsDataFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'army:audit-bsdata',
    description: 'Vérifie les unités extraites d\'une faction pour détecter des anomalies (armes manquantes, pollution de capacités)'
)]
class AuditBsDataCommand extends Command
{
    public function __construct(private BsDataFetcher $fetcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'sourceFile',
            InputArgument::REQUIRED,
            'Nom exact du fichier JSON de la faction, ex : "Leagues of Votann.json"'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceFile = $input->getArgument('sourceFile');

        $io->writeln("Téléchargement de {$sourceFile}...");
        $catalogue = $this->fetcher->fetchFactionCatalogue($sourceFile);
        $units = $this->fetcher->extractUnits($catalogue);

        $rows = [];
        foreach ($units as $unit) {
            $weaponCount = count($unit['statsData']['weapons'] ?? []);
            $abilityCount = count($unit['statsData']['abilities'] ?? []);

            $alerts = [];
            if ($weaponCount === 0) {
                $alerts[] = 'AUCUNE ARME';
            }
            if ($abilityCount > 15) {
                $alerts[] = 'TROP DE CAPACITÉS';
            }

            $rows[] = [$unit['name'], $weaponCount, $abilityCount, implode(', ', $alerts)];
        }

        $io->table(['Unité', 'Armes', 'Capacités', 'Alerte'], $rows);

        return Command::SUCCESS;
    }
}