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
    name: 'army:inspect-unit',
    description: 'Affiche toutes les informations brutes disponibles pour une unité (catégories, stats, armes, capacités, chemin de provenance)'
)]
class InspectUnitCommand extends Command
{
    public function __construct(private BsDataFetcher $fetcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('sourceFile', InputArgument::REQUIRED, 'ex: "Leagues of Votann.json"');
        $this->addArgument('unitName', InputArgument::REQUIRED, 'ex: "Hearthkyn Warriors" (recherche partielle acceptée)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceFile = $input->getArgument('sourceFile');
        $searchName = $input->getArgument('unitName');

        $catalogue = $this->fetcher->fetchFactionCatalogue($sourceFile);
        $cat = $catalogue['catalogue'] ?? [];

        $entriesById = [];
        foreach ($cat['sharedSelectionEntries'] ?? [] as $e) {
            $entriesById[$e['id']] = $e;
        }

        // Trouver l'entryLink de premier niveau correspondant (recherche partielle, insensible à la casse)
        $matchedLink = null;
        foreach ($cat['entryLinks'] ?? [] as $link) {
            if (($link['type'] ?? null) === 'selectionEntry' && stripos($link['name'] ?? '', $searchName) !== false) {
                $matchedLink = $link;
                break;
            }
        }

        if (!$matchedLink) {
            $io->error("Aucune unité trouvée pour \"{$searchName}\" dans {$sourceFile}");
            return Command::FAILURE;
        }

        $target = $entriesById[$matchedLink['targetId']] ?? null;
        if (!$target) {
            $io->error("entryLink trouvé mais cible introuvable (id: {$matchedLink['targetId']})");
            return Command::FAILURE;
        }

        $io->title($matchedLink['name']);

        // --- Catégories brutes ---
        $io->section('Catégories (categoryLinks)');
        $catRows = [];
        foreach ($target['categoryLinks'] ?? [] as $catLink) {
            $catRows[] = [
                $catLink['name'] ?? '',
                ($catLink['primary'] ?? false) ? 'OUI' : '',
            ];
        }
        $io->table(['Nom', 'Primary'], $catRows);

        // --- Parcours complet, SANS aucune exclusion, avec traçage du chemin ---
        $io->section('Parcours complet (tout, y compris Crusade/Enhancements) avec provenance');

        $groupsById = [];
        foreach ($cat['sharedSelectionEntryGroups'] ?? [] as $g) {
            $groupsById[$g['id']] = $g;
        }
        $profilesById = [];
        foreach ($cat['sharedProfiles'] ?? [] as $p) {
            $profilesById[$p['id']] = $p;
        }

        $found = [];
        $visited = [];
        $this->walkAndTrace($target, 'racine', $found, $visited, $entriesById, $groupsById, $profilesById);

        $statsRows = [];
        $weaponRows = [];
        $abilityRows = [];

        foreach ($found as $item) {
            if ($item['typeName'] === 'Unit') {
                $chars = $item['chars'];
                $statsRows[] = [$item['name'], $chars['M'] ?? '', $chars['T'] ?? '', $chars['Sv'] ?? '', $chars['W'] ?? '', $chars['LD'] ?? '', $chars['OC'] ?? '', $item['path']];
            } elseif (in_array($item['typeName'], ['Ranged Weapons', 'Melee Weapons'], true)) {
                $chars = $item['chars'];
                $weaponRows[] = [$item['name'], $chars['Range'] ?? '', $chars['S'] ?? '', $chars['AP'] ?? '', $chars['D'] ?? '', $item['path']];
            } elseif ($item['typeName'] === 'Abilities') {
                $abilityRows[] = [$item['name'], $item['path']];
            }
        }

        $io->writeln('<comment>Profils "Unit" (stats)</comment>');
        $io->table(['Nom', 'M', 'T', 'Sv', 'W', 'LD', 'OC', 'Provenance (chemin)'], $statsRows);

        $io->writeln('<comment>Armes</comment>');
        $io->table(['Nom', 'Range', 'S', 'AP', 'D', 'Provenance (chemin)'], $weaponRows);

        $io->writeln('<comment>Capacités</comment>');
        $io->table(['Nom', 'Provenance (chemin)'], $abilityRows);

        return Command::SUCCESS;
    }

    private function walkAndTrace(
        array $node,
        string $path,
        array &$found,
        array &$visited,
        array $entriesById,
        array $groupsById,
        array $profilesById
    ): void {
        foreach ($node['profiles'] ?? [] as $profile) {
            $chars = [];
            foreach ($profile['characteristics'] ?? [] as $c) {
                $chars[$c['name']] = $c['$text'] ?? '';
            }
            $found[] = [
                'name' => $profile['name'],
                'typeName' => $profile['typeName'] ?? null,
                'chars' => $chars,
                'path' => $path,
            ];
        }

        foreach ($node['infoLinks'] ?? [] as $il) {
            if (($il['type'] ?? null) === 'profile' && isset($profilesById[$il['targetId']])) {
                $p = $profilesById[$il['targetId']];
                $chars = [];
                foreach ($p['characteristics'] ?? [] as $c) {
                    $chars[$c['name']] = $c['$text'] ?? '';
                }
                $found[] = [
                    'name' => $p['name'],
                    'typeName' => $p['typeName'] ?? null,
                    'chars' => $chars,
                    'path' => $path . ' > infoLink:' . ($il['name'] ?? ''),
                ];
            }
        }

        foreach ($node['selectionEntries'] ?? [] as $child) {
            $this->walkAndTrace($child, $path . ' > ' . ($child['name'] ?? '?'), $found, $visited, $entriesById, $groupsById, $profilesById);
        }

        foreach ($node['selectionEntryGroups'] ?? [] as $group) {
            $this->walkAndTrace($group, $path . ' > [groupe]' . ($group['name'] ?? '?'), $found, $visited, $entriesById, $groupsById, $profilesById);
        }

        foreach ($node['entryLinks'] ?? [] as $link) {
            $targetId = $link['targetId'] ?? null;
            $linkPath = $path . ' > [LIEN]' . ($link['name'] ?? '?');

            if ($targetId && !isset($visited[$targetId])) {
                $visited[$targetId] = true;
                if (($link['type'] ?? null) === 'selectionEntry' && isset($entriesById[$targetId])) {
                    $this->walkAndTrace($entriesById[$targetId], $linkPath, $found, $visited, $entriesById, $groupsById, $profilesById);
                } elseif (($link['type'] ?? null) === 'selectionEntryGroup' && isset($groupsById[$targetId])) {
                    $this->walkAndTrace($groupsById[$targetId], $linkPath, $found, $visited, $entriesById, $groupsById, $profilesById);
                }
            }

            $this->walkAndTrace($link, $linkPath, $found, $visited, $entriesById, $groupsById, $profilesById);
        }
    }
}