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
    // Valeur du <select faction> côté formulaire => nom du fichier JSON dans BSData/wh40k-11e
    private const FACTION_FILES = [
        'Space Marines' => 'Imperium - Space Marines.json',
        'Blood Angels' => 'Imperium - Blood Angels.json',
        'Dark Angels' => 'Imperium - Dark Angels.json',
        'Space Wolves' => 'Imperium - Space Wolves.json',
        'Grey Knights' => 'Imperium - Grey Knights.json',
        'Deathwatch' => 'Imperium - Deathwatch.json',
        'Adeptus Custodes' => 'Imperium - Adeptus Custodes.json',
        'Sisters of Battle' => 'Imperium - Adepta Sororitas.json',
        'Astra Militarum' => 'Imperium - Astra Militarum.json',
        'Adeptus Mechanicus' => 'Imperium - Adeptus Mechanicus.json',
        'Imperial Knights' => 'Imperium - Imperial Knights.json',
        'Chaos Space Marines' => 'Chaos - Chaos Space Marines.json',
        'Death Guard' => 'Chaos - Death Guard.json',
        'Thousand Sons' => 'Chaos - Thousand Sons.json',
        'World Eaters' => 'Chaos - World Eaters.json',
        "Emperor's Children" => "Chaos - Emperor's Children.json",
        'Chaos Knights' => 'Chaos - Chaos Knights.json',
        'Daemons' => 'Chaos - Chaos Daemons.json',
        'Orks' => 'Orks.json',
        'Eldar' => 'Aeldari - Craftworlds.json',
        'Dark Eldar' => 'Aeldari - Drukhari.json',
        'Tyranids' => 'Tyranids.json',
        'Genestealer Cults' => 'Genestealer Cults.json',
        'Tau' => "T'au Empire.json",
        'Necrons' => 'Necrons.json',
        'Leagues of Votann' => 'Leagues of Votann.json',
    ];

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

    private const EXTRACTOR_VERSION = 10; // à incrémenter à chaque fois qu'on change ce qu'on extrait

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $factionArg = $input->getArgument('faction');

        $facs = $factionArg
            ? [$factionArg => self::FACTION_FILES[$factionArg] ?? null]
            : self::FACTION_FILES;

        foreach ($facs as $factionLabel => $sourceFile) {
            if (!$sourceFile) {
                $io->error("Faction inconnue : {$factionLabel}");
                continue;
            }

            $io->section($factionLabel);

            $syncState = $this->em->getRepository(FactionSyncState::class)
                ->findOneBy(['sourceFile' => $sourceFile]);

            $latestSha = $this->fetcher->getLatestCommitSha($sourceFile);
            $shaChanged = !$syncState || $syncState->getLastCommitSha() !== $latestSha;
            $currentVersion = $syncState?->getExtractorVersion() ?? 0;
            $versionOutdated = $currentVersion < self::EXTRACTOR_VERSION;

            if (!$shaChanged && !$versionOutdated) {
                $io->writeln('  À jour (SHA inchangé, extraction déjà à la dernière version). Rien à faire.');
                continue;
            }

            $io->writeln('  SHA : ' . ($shaChanged ? 'changement détecté' : 'inchangé'));
            $io->writeln('  Version extraction : ' . ($versionOutdated ? "obsolète ({$currentVersion} → " . self::EXTRACTOR_VERSION . ')' : 'à jour'));
            $io->writeln('  Téléchargement du catalogue...');

            $catalogue = $this->fetcher->fetchFactionCatalogue($sourceFile);

            // --- Unités ---
            $units = $this->fetcher->extractUnits($catalogue);
            $unitCount = 0;
            foreach ($units as $unitData) {
                $unit = $this->em->getRepository(FactionUnit::class)
                    ->findOneBy(['bsdataId' => $unitData['bsdataId']]);

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
                $unitCount++;
            }
            $io->writeln("  Unités : {$unitCount} synchronisées.");

            // --- Détachements ---
            $detachments = $this->fetcher->extractDetachments($catalogue);
            $detachmentCount = 0;
            foreach ($detachments as $detData) {
                $detachment = $this->em->getRepository(FactionDetachement::class)
                    ->findOneBy(['bsdataId' => $detData['bsdataId']]);

                if (!$detachment) {
                    $detachment = new FactionDetachement();
                    $this->em->persist($detachment);
                }

                $detachment->setBsdataId($detData['bsdataId']);
                $detachment->setName($detData['name']);
                $detachment->setFaction($factionLabel);
                $detachment->setSourceFile($sourceFile);
                $detachmentCount++;
            }
            $io->writeln("  Détachements : {$detachmentCount} synchronisés.");

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

            $io->success("Synchro terminée pour {$factionLabel}.");
        }

        return Command::SUCCESS;
    }
}