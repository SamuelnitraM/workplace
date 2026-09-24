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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Synchronise faction_unit / faction_detachement depuis BSData.
 *
 * Détection de changement : UN appel à l'API GitHub (arbre du dépôt = SHA de blob de tous les fichiers) ;
 * l'empreinte combinée des fichiers du graphe de la faction (catalogue principal, bibliothèques liées,
 * système de jeu) est comparée à celle enregistrée. Une faction n'est retéléchargée / réextraite que si
 * l'un de ces fichiers a changé ou si EXTRACTOR_VERSION a augmenté.
 *
 * Les listes d'armée gardent leur propre copie des unités (ArmyUnit, sans clé étrangère vers FactionUnit) :
 * supprimer ou renommer des FactionUnit ne les casse pas.
 */
#[AsCommand(name: 'army:sync-bsdata', description: 'Synchronise les unités depuis BSData (GitHub)')]
class SyncBsDataCommand extends Command
{
    private const EXTRACTOR_VERSION = 13; // à incrémenter à chaque fois qu'on change ce qu'on extrait

    public function __construct(
        private BsDataFetcher $fetcher,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'faction',
                InputArgument::OPTIONAL,
                'Faction à synchroniser (ex: "Leagues of Votann"). Si omis : toutes les factions.'
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Réextrait même si rien n\'a changé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        @ini_set('memory_limit', '1024M');
        $factionArg = $input->getArgument('faction');
        $force = (bool) $input->getOption('force');

        $facs = $factionArg
            ? [$factionArg => BsDataFetcher::FACTION_FILES[$factionArg] ?? null]
            : BsDataFetcher::FACTION_FILES;

        if ($this->fetcher->getRepoTree() === null) {
            // API GitHub indisponible / limite de requêtes : on ne touche à rien
            $io->error('Arbre du dépôt BSData introuvable (API GitHub indisponible ou limite de requêtes atteinte). Rien n\'a été modifié.');

            return Command::FAILURE;
        }

        $failures = [];

        foreach ($facs as $factionLabel => $sourceFile) {
            if (!$sourceFile) {
                $io->error("Faction inconnue : {$factionLabel}");
                $failures[] = $factionLabel;
                continue;
            }

            $io->section($factionLabel);

            try {
                $this->syncFaction($io, $factionLabel, $sourceFile, $force);
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
            } finally {
                // Les fichiers restent en cache disque ; on libère la mémoire entre deux factions
                $this->fetcher->clearMemoryCache();
            }
        }

        if ($failures) {
            $io->warning('Factions en échec : ' . implode(', ', $failures));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function syncFaction(SymfonyStyle $io, string $factionLabel, string $sourceFile, bool $force): void
    {
        $syncState = $this->em->getRepository(FactionSyncState::class)
            ->findOneBy(['sourceFile' => $sourceFile]);

        $currentVersion = $syncState?->getExtractorVersion() ?? 0;
        $versionOutdated = $currentVersion < self::EXTRACTOR_VERSION;

        // Empreinte des fichiers connus de la dernière synchro : aucun téléchargement si rien n'a bougé
        $knownFiles = $syncState?->getSourceFiles();
        if (!$force && !$versionOutdated && $knownFiles) {
            $knownHash = $this->fetcher->combinedHash($knownFiles);
            if ($knownHash !== null && $knownHash === $syncState->getLastCommitSha()) {
                $io->writeln('  À jour (fichiers inchangés, extraction déjà à la dernière version). Rien à faire.');

                return;
            }
        }

        $io->writeln('  Chargement du graphe de catalogues...');
        $graph = $this->fetcher->loadGraph($sourceFile);
        $files = $graph->files();
        $hash = $this->fetcher->combinedHash($files);
        if ($hash === null) {
            throw new \RuntimeException('Empreinte des fichiers impossible à calculer (fichier absent de l\'arbre du dépôt).');
        }

        $io->writeln('  Fichiers : ' . implode(', ', $files));
        $io->writeln('  Fichiers : ' . ($syncState?->getLastCommitSha() === $hash ? 'inchangés' : 'changement détecté'));
        $io->writeln('  Version extraction : ' . ($versionOutdated ? "obsolète ({$currentVersion} → " . self::EXTRACTOR_VERSION . ')' : 'à jour'));

        // --- Unités ---
        $unitRepo = $this->em->getRepository(FactionUnit::class);
        $result = $this->fetcher->extractUnits($graph);
        $units = $result['units'];
        if (!$units) {
            // Garde-fou : on ne vide pas la faction si l'extraction ne renvoie rien
            throw new \RuntimeException('Aucune unité extraite du catalogue, synchro annulée.');
        }

        $syncedUnitIds = [];
        foreach ($units as $unitData) {
            // Un même id BSData peut exister dans plusieurs factions : on cherche par (id, faction)
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
            $unit->setSourceFile(mb_substr($unitData['sourceFile'], 0, 100));
            $unit->setStatsData($unitData['statsData']);
            $syncedUnitIds[$unitData['bsdataId']] = $unit;
        }
        $unitCount = count($syncedUnitIds);
        $legendsCount = count(array_filter($units, fn(array $u) => $u['legends']));
        $io->writeln("  Unités : {$unitCount} synchronisées (dont {$legendsCount} Legends), " . count($result['excluded']) . ' entrée(s) écartée(s).');

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
        $detachments = $this->fetcher->extractDetachments($graph);
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
            $detachment->setSourceFile(mb_substr($graph->sourceOf($detData['bsdataId']) ?? $sourceFile, 0, 100));
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
        $syncState->setLastCommitSha($hash);
        $syncState->setSourceFiles($files);
        $syncState->setLastSyncedAt(new \DateTimeImmutable());
        $syncState->setUnitCount($unitCount);
        $syncState->setExtractorVersion(self::EXTRACTOR_VERSION);

        $this->em->flush();
        $this->em->clear();

        $io->success("Synchro terminée pour {$factionLabel}.");
    }
}
