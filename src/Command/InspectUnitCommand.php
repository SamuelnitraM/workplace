<?php

namespace App\Command;

use App\BsData\CatalogueGraph;
use App\Service\BsDataFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'army:inspect-unit',
    description: 'Affiche ce qui est extrait de BSData pour une unité (points, figurines, armes, capacités, mots-clés) et, avec --tree, l\'arbre brut avec provenance'
)]
class InspectUnitCommand extends Command
{
    public function __construct(private BsDataFetcher $fetcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('faction', InputArgument::REQUIRED, 'Faction (ex : "Leagues of Votann") ou fichier du catalogue (ex : "Leagues of Votann.json")')
            ->addArgument('unitName', InputArgument::REQUIRED, 'ex : "Hearthkyn Warriors" (recherche partielle acceptée, correspondance exacte prioritaire)')
            ->addOption('tree', null, InputOption::VALUE_NONE, 'Affiche aussi l\'arbre brut (liens résolus, profils, fichier source)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        @ini_set('memory_limit', '1024M');
        $faction = (string) $input->getArgument('faction');
        $search = (string) $input->getArgument('unitName');

        $file = BsDataFetcher::FACTION_FILES[$faction] ?? (in_array($faction, BsDataFetcher::FACTION_FILES, true) ? $faction : null);
        if ($file === null) {
            $io->error("Faction inconnue : {$faction}");

            return Command::FAILURE;
        }

        $graph = $this->fetcher->loadGraph($file);
        $result = $this->fetcher->extractUnits($graph);

        $unit = null;
        foreach ($result['units'] as $candidate) {
            if (strcasecmp($candidate['name'], $search) === 0) {
                $unit = $candidate;
                break;
            }
            if ($unit === null && stripos($candidate['name'], $search) !== false) {
                $unit = $candidate;
            }
        }

        if ($unit === null) {
            $io->error("Aucune unité jouable trouvée pour \"{$search}\" ({$file}).");
            foreach ($result['excluded'] as $e) {
                if (stripos($e['name'], $search) !== false) {
                    $io->writeln(sprintf('  Entrée écartée : %s — %s', $e['name'], $e['reason']));
                }
            }

            return Command::FAILURE;
        }

        $s = $unit['statsData'];
        $io->title($unit['name'] . ($s['legends'] ? ' [Legends]' : ''));
        $io->definitionList(
            ['Id BSData' => $unit['bsdataId']],
            ['Fichier source' => $unit['sourceFile']],
            ['Catégorie' => (string) $unit['category']],
            ['Points' => implode(' | ', array_map(fn($o) => "{$o['models']} fig. = {$o['points']} pts", $s['pointsOptions']))],
            ['Mots-clés' => implode(', ', $s['keywords'])],
            ['Faction' => implode(', ', $s['factionKeywords'])],
            ['Invulnérable' => $s['invulnerableSave'] ?? '-'],
            ['Capacités de base' => implode(', ', $s['coreAbilities']) ?: '-'],
            ['Capacités de faction' => implode(', ', $s['factionAbilities']) ?: '-'],
            ['Transport' => $s['transport'] ?? '-'],
        );

        foreach ($s['models'] as $model) {
            $io->section('Figurine : ' . $model['name']);
            $io->table(array_keys($model['stats']), [array_values($model['stats'])]);
            $io->table(['Arme', 'Type', 'Portée', 'A', 'CT/CC', 'F', 'PA', 'D', 'Mots-clés'], array_map(fn($w) => [
                $w['name'], $w['weaponType'] === 'Melee Weapons' ? 'Mêlée' : 'Tir', $w['Range'] ?? '', $w['A'] ?? '',
                $w['BS'] ?? $w['WS'] ?? '', $w['S'] ?? '', $w['AP'] ?? '', $w['D'] ?? '', $w['Keywords'] ?? '',
            ], $model['weapons']));
        }
        foreach ($s['roles'] as $role) {
            $io->section('Rôle : ' . $role['name']);
            $io->writeln('  Armes : ' . (implode(', ', array_column($role['weapons'], 'name')) ?: '-'));
            $io->writeln('  Capacités : ' . (implode(', ', array_column($role['abilities'], 'name')) ?: '-'));
        }
        $io->section('Capacités');
        $io->listing(array_map(fn($a) => $a['name'] . ' — ' . mb_strimwidth($a['description'], 0, 110, '…'), $s['abilities']));

        if ($input->getOption('tree')) {
            $io->section('Arbre brut (avec provenance)');
            $entry = $graph->get($unit['bsdataId']);
            if ($entry !== null) {
                $this->printTree($io, $graph, $entry, null, 0, [$entry['id'] => true]);
            }
        }

        return Command::SUCCESS;
    }

    private function printTree(SymfonyStyle $io, CatalogueGraph $graph, array $node, ?array $link, int $depth, array $visited): void
    {
        if ($depth > 12) {
            return;
        }
        $pad = str_repeat('  ', $depth);
        $io->writeln(sprintf('%s- %s%s [%s] (%s)', $pad, $link ? '→ ' : '', $link['name'] ?? $node['name'] ?? '?', $node['type'] ?? 'groupe', $graph->sourceOf($node['id'] ?? '') ?? 'inline'));
        foreach (array_filter([$link, $node]) as $part) {
            foreach ($part['profiles'] ?? [] as $p) {
                $io->writeln(sprintf('%s    P<%s> %s', $pad, $p['typeName'] ?? '?', $p['name'] ?? ''));
            }
            foreach ($part['infoLinks'] ?? [] as $il) {
                $io->writeln(sprintf('%s    I<%s> %s (%s)', $pad, $il['type'] ?? '?', $il['name'] ?? '', $graph->sourceOf($il['targetId'] ?? '') ?? 'introuvable'));
            }
            foreach ($part['selectionEntries'] ?? [] as $child) {
                $this->printTree($io, $graph, $child, null, $depth + 1, $visited);
            }
            foreach ($part['selectionEntryGroups'] ?? [] as $child) {
                $this->printTree($io, $graph, $child, null, $depth + 1, $visited);
            }
            foreach ($part['entryLinks'] ?? [] as $childLink) {
                $target = $graph->get($childLink['targetId'] ?? null);
                if ($target === null) {
                    $io->writeln(sprintf('%s  - → %s (cible introuvable)', $pad, $childLink['name'] ?? '?'));
                } elseif (!isset($visited[$target['id']])) {
                    $this->printTree($io, $graph, $target, $childLink, $depth + 1, $visited + [$target['id'] => true]);
                }
            }
        }
    }
}
