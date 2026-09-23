<?php

namespace App\Command;

use App\Entity\FactionDetachement;
use App\Entity\FactionSyncState;
use App\Entity\FactionUnit;
use App\Service\BsDataFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'army:sync-bsdata', description: 'Synchronise les unités depuis BSData (GitHub)')]
class SyncBsDataCommand extends Command
{
    public function __construct(
        private BsDataFetcher $fetcher,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'faction',
            InputArgument::OPTIONAL,
            'Faction à synchroniser (ex: "Leagues of Votann"). Si omis : toutes les factions.'
        );
    }

    private const EXTRACTOR_VERSION = 12; // à incrémenter à chaque fois qu'on change ce qu'on extrait

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $factionArg = $input->getArgument('faction');

        $facs = $factionArg
            ? [$factionArg => BsDataFetcher::FACTION_FILES[$factionArg] ?? null]
            : BsDataFetcher::FACTION_FILES;

        $failures = [];

        foreach ($facs as $factionLabel => $sourceFile) {
            if (!$sourceFile) {
                $io->error("Faction inconnue : {$factionLabel}");
                $failures[] = $factionLabel;
                continue;
            }

            $io->section($factionLabel);

            try {
                $this->syncFaction($io, $factionLabel, $sourceFile);
            } catch (\Throwable $e) {
                $failures[] = $factionLabel;
                $io->error("Échec de la synchro pour {$factionLabel} : " . $e->getMessage());

                // On repart d'un état propre pour ne pas polluer la faction suivante
                if ($this->em->isOpen()) {
                    $this->em->clear();
                } else {
                    // EntityManager fermé après une erreur SQL : impossible de continuer proprement
                    $io->error('EntityManager fermé, arrêt de la synchronisation.');
                    return Command::FAILURE;
                }
            }
        }

        if ($failures) {
            $io->warning('Factions en échec : ' . implode(', ', $failures));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function syncFaction(SymfonyStyle $io, string $factionLabel, string $sourceFile): void
    {
        $syncState = $this->em->getRepository(FactionSyncState::class)
            ->findOneBy(['sourceFile' => $sourceFile]);

        $latestSha = $this->fetcher->getLatestCommitSha($sourceFile);
        if ($latestSha === null) {
            // API GitHub indisponible / limite de requêtes / fichier absent : on ne touche à rien
            $io->warning('  SHA du dernier commit introuvable (API GitHub indisponible ?). Faction ignorée.');
            return;
        }

        $shaChanged = !$syncState || $syncState->getLastCommitSha() !== $latestSha;
        $currentVersion = $syncState?->getExtractorVersion() ?? 0;
        $versionOutdated = $currentVersion < self::EXTRACTOR_VERSION;

        if (!$shaChanged && !$versionOutdated) {
            $io->writeln('  À jour (SHA inchangé, extraction déjà à la dernière version). Rien à faire.');
            return;
        }

        $io->writeln('  SHA : ' . ($shaChanged ? 'changement détecté' : 'inchangé'));
        $io->writeln('  Version extraction : ' . ($versionOutdated ? "obsolète ({$currentVersion} → " . self::EXTRACTOR_VERSION . ')' : 'à jour'));
        $io->writeln('  Téléchargement du catalogue...');

        $catalogue = $this->fetcher->fetchFactionCatalogue($sourceFile);
        if (!isset($catalogue['catalogue'])) {
            throw new \RuntimeException('Catalogue JSON invalide (clé "catalogue" absente).');
        }

        // --- Unités ---
        $unitRepo = $this->em->getRepository(FactionUnit::class);
        $units = $this->fetcher->extractUnits($catalogue);
        if (!$units) {
            // Garde-fou : on ne vide pas la faction si l'extraction ne renvoie rien
            throw new \RuntimeException('Aucune unité extraite du catalogue, synchro annulée.');
        }
        $syncedUnitIds = [];
        foreach ($units as $unitData) {
            // Un même id BSData peut exister dans plusieurs catalogues : on cherche par (id, faction)
            $unit = $syncedUnitIds[$unitData['bsdataId']]
                ?? $unitRepo->findOneBy(['bsdataId' => $unitData['bsdataId'], 'faction' => $factionLabel]);

            if (!$unit) {
                $unit = new FactionUnit();
                $this->em->persist($unit);
            }

            $unit->setBsdataId($unitData['bsdataId']);
            $unit->setName($unitData['name']);
            $unit->setFaction($factionLabel);
            $unit->setCategory($unitData['category']);
            $unit->setPoints($unitData['points']);
            $unit->setSourceFile($sourceFile);
            $unit->setStatsData($unitData['statsData']);
            $syncedUnitIds[$unitData['bsdataId']] = $unit;
        }
        $unitCount = count($syncedUnitIds);
        $io->writeln("  Unités : {$unitCount} synchronisées.");

        // Suppression des unités qui ont disparu du catalogue.
        // (ArmyUnit ne référence pas FactionUnit : les listes existantes gardent leur copie.)
        $removedUnits = 0;
        foreach ($unitRepo->findBy(['faction' => $factionLabel]) as $existing) {
            if (!isset($syncedUnitIds[$existing->getBsdataId()])) {
                $this->em->remove($existing);
                $removedUnits++;
            }
        }
        if ($removedUnits) {
            $io->writeln("  Unités : {$removedUnits} obsolète(s) supprimée(s).");
        }

        // --- Détachements ---
        $detRepo = $this->em->getRepository(FactionDetachement::class);
        $detachments = $this->fetcher->extractDetachments($catalogue);
        $syncedDetIds = [];
        foreach ($detachments as $detData) {
            $detachment = $syncedDetIds[$detData['bsdataId']]
                ?? $detRepo->findOneBy(['bsdataId' => $detData['bsdataId'], 'faction' => $factionLabel]);

            if (!$detachment) {
                $detachment = new FactionDetachement();
                $this->em->persist($detachment);
            }

            $detachment->setBsdataId($detData['bsdataId']);
            $detachment->setName($detData['name']);
            $detachment->setFaction($factionLabel);
            $detachment->setSourceFile($sourceFile);
            $syncedDetIds[$detData['bsdataId']] = $detachment;
        }
        $io->writeln('  Détachements : ' . count($syncedDetIds) . ' synchronisés.');

        // ArmyList.detachment est une simple chaîne : aucune contrainte à respecter
        $removedDets = 0;
        foreach ($detRepo->findBy(['faction' => $factionLabel]) as $existing) {
            if (!isset($syncedDetIds[$existing->getBsdataId()])) {
                $this->em->remove($existing);
                $removedDets++;
            }
        }
        if ($removedDets) {
            $io->writeln("  Détachements : {$removedDets} obsolète(s) supprimé(s).");
        }

        // --- État de synchro ---
        if (!$syncState) {
            $syncState = new FactionSyncState();
            $syncState->setSourceFile($sourceFile);
            $this->em->persist($syncState);
        }
        $syncState->setLastCommitSha($latestSha);
        $syncState->setLastSyncedAt(new \DateTimeImmutable());
        $syncState->setUnitCount($unitCount);
        $syncState->setExtractorVersion(self::EXTRACTOR_VERSION);

        $this->em->flush();
        $this->em->clear();

        $io->success("Synchro terminée pour {$factionLabel}.");
    }
}
