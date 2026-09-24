<?php

namespace App\Command;

use App\Entity\FactionUnit;
use App\Service\BsDataFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'army:audit-bsdata',
    description: 'Audit des unités par faction : nombre, Legends, unités sans figurine / arme / capacité / points'
)]
class AuditBsDataCommand extends Command
{
    public function __construct(
        private BsDataFetcher $fetcher,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('faction', InputArgument::OPTIONAL, 'Faction à auditer (ex : "Leagues of Votann"). Si omis : toutes.')
            ->addOption('live', null, InputOption::VALUE_NONE, 'Extrait depuis BSData au lieu de lire la base (affiche aussi les entrées écartées)')
            ->addOption('details', 'd', InputOption::VALUE_NONE, 'Liste les unités en anomalie (et les entrées écartées avec --live)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        @ini_set('memory_limit', '1024M');
        $factionArg = $input->getArgument('faction');
        $live = (bool) $input->getOption('live');
        $details = (bool) $input->getOption('details');

        if ($factionArg !== null && !isset(BsDataFetcher::FACTION_FILES[$factionArg])) {
            $io->error("Faction inconnue : {$factionArg}");

            return Command::FAILURE;
        }
        $factions = $factionArg !== null ? [$factionArg] : array_keys(BsDataFetcher::FACTION_FILES);

        $rows = [];
        $offenders = [];
        foreach ($factions as $faction) {
            $excluded = [];
            if ($live) {
                $result = $this->fetcher->extractUnits($this->fetcher->loadGraph(BsDataFetcher::FACTION_FILES[$faction]));
                $this->fetcher->clearMemoryCache();
                $units = array_map(fn(array $u) => ['name' => $u['name'], 'points' => $u['points'], 'statsData' => $u['statsData']], $result['units']);
                $excluded = $result['excluded'];
            } else {
                $units = array_map(fn(FactionUnit $u) => ['name' => $u->getName(), 'points' => $u->getPoints(), 'statsData' => $u->getStatsData() ?? []],
                    $this->em->getRepository(FactionUnit::class)->findBy(['faction' => $faction], ['name' => 'ASC']));
            }

            $stats = ['legends' => 0, 'noModels' => [], 'noWeapons' => [], 'noAbilities' => [], 'noPoints' => []];
            foreach ($units as $unit) {
                $s = $unit['statsData'];
                if ($s['legends'] ?? false) {
                    $stats['legends']++;
                }
                $weapons = 0;
                foreach ($s['models'] ?? [] as $model) {
                    $weapons += count($model['weapons'] ?? []);
                }
                foreach ($s['roles'] ?? [] as $role) {
                    $weapons += count($role['weapons'] ?? []);
                }
                if (($s['models'] ?? []) === []) {
                    $stats['noModels'][] = $unit['name'];
                }
                if ($weapons === 0) {
                    $stats['noWeapons'][] = $unit['name'];
                }
                if (($s['abilities'] ?? []) === [] && ($s['coreAbilities'] ?? []) === [] && ($s['factionAbilities'] ?? []) === []) {
                    $stats['noAbilities'][] = $unit['name'];
                }
                if ((int) $unit['points'] <= 0) {
                    $stats['noPoints'][] = $unit['name'];
                }
            }

            $rows[] = [
                $faction, count($units), $stats['legends'], count($stats['noModels']), count($stats['noWeapons']),
                count($stats['noAbilities']), count($stats['noPoints']), $live ? count($excluded) : '-',
            ];
            foreach (['noModels' => 'sans figurine', 'noWeapons' => 'sans arme', 'noAbilities' => 'sans capacité', 'noPoints' => 'sans points'] as $key => $label) {
                if ($stats[$key]) {
                    $offenders[] = [$faction, $label, implode(', ', $stats[$key])];
                }
            }
            if ($details && $excluded) {
                $byReason = [];
                foreach ($excluded as $e) {
                    $byReason[$e['reason']][] = $e['name'];
                }
                foreach ($byReason as $reason => $names) {
                    $offenders[] = [$faction, 'écartée : ' . $reason, implode(', ', $names)];
                }
            }
        }

        $io->title('Audit BSData' . ($live ? ' (extraction en direct)' : ' (base de données)'));
        $io->table(['Faction', 'Unités', 'Legends', 'Sans figurine', 'Sans arme', 'Sans capacité', 'Sans points', 'Écartées'], $rows);

        if ($offenders) {
            $io->section('Unités en anomalie' . ($details ? ' et entrées écartées' : ''));
            $io->table(['Faction', 'Problème', 'Unités'], $offenders);
        } else {
            $io->success('Aucune anomalie.');
        }

        return Command::SUCCESS;
    }
}
