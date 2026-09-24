<?php

namespace App\Command;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Crée l'arborescence de référence du forum (miniatures, Warhammer, autres wargames, maquettisme).
 *
 * Idempotente et non destructive : une catégorie est retrouvée par son nom (insensible à la casse)
 * sous le même parent ; seules les catégories manquantes sont créées, à la suite de leurs sœurs.
 * Les catégories existantes (nom, slug, description, position, option « création de sujets ») ne sont jamais modifiées.
 */
#[AsCommand(name: 'app:forum:seed-categories', description: 'Crée les catégories de référence du forum manquantes')]
class SeedForumCategoriesCommand extends Command
{
    /**
     * [nom, description, création de sujets autorisée, enfants]
     * Une catégorie à false sert de regroupement : ses sous-catégories sont affichées en liste.
     */
    private const TREE = [
        ['Warhammer', 'L\'univers de Games Workshop : Warhammer 40,000, Age of Sigmar, The Old World et les jeux spécialistes.', false, [
            ['Warhammer 40K', 'Le 41e millénaire : factions, règles, listes et figurines.', true, [
                ['Imperium', 'Les forces de l\'Imperium de l\'Humanité.', true, [
                    ['Space Marines', 'Les Adeptus Astartes et leurs chapitres.', true, [
                        ['Blood Angels', 'Les fils de Sanguinius.', true, []],
                        ['Dark Angels', 'La Première Légion et ses secrets.', true, []],
                        ['Space Wolves', 'Les loups de Fenris.', true, []],
                        ['Black Templars', 'Les croisés de l\'Empereur.', true, []],
                    ]],
                    ['Adepta Sororitas', 'Les Sœurs de Bataille.', true, []],
                    ['Adeptus Custodes', 'La garde de l\'Empereur.', true, []],
                    ['Adeptus Mechanicus', 'Les prêtres de Mars.', true, []],
                    ['Grey Knights', 'Les chasseurs de démons.', true, []],
                    ['Imperial Knights', 'Les chevaliers de l\'Imperium.', true, []],
                ]],
                ['Chaos', 'Les serviteurs des Dieux Sombres.', true, [
                    ['Chaos Space Marines', 'Les légions renégates.', true, []],
                    ['Death Guard', 'Les élus de Nurgle.', true, []],
                    ['Thousand Sons', 'Les sorciers de Tzeentch.', true, []],
                    ['World Eaters', 'Les berserkers de Khorne.', true, []],
                    ['Emperor\'s Children', 'Les zélateurs de Slaanesh.', true, []],
                    ['Démons du Chaos', 'Les légions démoniaques.', true, []],
                    ['Chaos Knights', 'Les chevaliers renégats.', true, []],
                ]],
                ['Xenos', 'Les races extraterrestres de la galaxie.', true, [
                    ['Aeldari', 'Vaisseaux-mondes, Arlequins et Ynnari.', true, []],
                    ['Drukhari', 'Les pillards de Commorragh.', true, []],
                    ['Nécrons', 'Les dynasties endormies.', true, []],
                    ['T\'au Empire', 'Pour le Bien Suprême.', true, []],
                    ['Tyranides', 'L\'esprit de la ruche.', true, []],
                    ['Genestealer Cults', 'Les cultes de l\'infiltration.', true, []],
                ]],
                ['Kill Team', 'Escarmouches d\'escouades d\'élite dans le 41e millénaire.', true, []],
            ]],
            ['Age of Sigmar', 'Les Royaumes Mortels : alliances, règles et armées.', true, [
                ['Ordre', 'Stormcast Eternals, Cities of Sigmar, Lumineth, Seraphon, Sylvaneth…', true, []],
                ['Chaos', 'Slaves to Darkness, Skaven, Blades of Khorne, Maggotkin…', true, []],
                ['Mort', 'Soulblight Gravelords, Nighthaunt, Ossiarch Bonereapers, Flesh-eater Courts.', true, []],
                ['Destruction', 'Orruk Warclans, Gloomspite Gitz, Ogor Mawtribes, Sons of Behemat.', true, []],
                ['Warcry', 'Escarmouches de bandes dans les Royaumes Mortels.', true, []],
            ]],
            ['The Old World', 'Le retour du Monde qui Est en format rang et file.', true, []],
            ['L\'Hérésie d\'Horus', 'La guerre fratricide des légions Astartes (Horus Heresy).', true, []],
            ['Jeux spécialistes', 'Les autres jeux de Games Workshop.', true, [
                ['Necromunda', 'Guerres de gangs dans les sous-ruches.', true, []],
                ['Blood Bowl', 'Le football fantastique le plus violent de l\'univers.', true, []],
                ['Warhammer Underworlds', 'Duels de bandes rapides et stratégiques.', true, []],
                ['Le Seigneur des Anneaux', 'Le jeu de bataille stratégique en Terre du Milieu.', true, []],
            ]],
        ]],
        ['Autres wargames', 'Tous les jeux de figurines hors Warhammer.', false, [
            ['Wargames historiques', 'Antiquité, médiéval, napoléonien, Seconde Guerre mondiale (Bolt Action…).', true, []],
            ['Star Wars', 'Légion, Shatterpoint, X-Wing…', true, []],
            ['Marvel Crisis Protocol', 'Super-héros et escarmouches.', true, []],
            ['Infinity', 'Escarmouche science-fiction de Corvus Belli.', true, []],
            ['Warmachine & Hordes', 'Steampunk et fantasy des Royaumes d\'Acier.', true, []],
            ['Conquest', 'Batailles de masse de Para Bellum.', true, []],
            ['Trench Crusade', 'Guerre de tranchées dans un enfer alternatif.', true, []],
            ['Malifaux', 'Escarmouche gothique et narrative.', true, []],
            ['Escarmouche fantastique', 'Frostgrave, Stargrave, Mordheim et consorts.', true, []],
            ['Autres jeux', 'Jeux indépendants, jeux de plateau avec figurines, créations maison.', true, []],
        ]],
        ['Maquettisme', 'Maquettes plastiques, résine et bois : montage, peinture et vieillissement.', false, [
            ['Aviation', 'Avions et hélicoptères, toutes époques.', true, []],
            ['Blindés et véhicules militaires', 'Chars, véhicules et artillerie.', true, []],
            ['Marine', 'Navires, sous-marins et voiliers.', true, []],
            ['Automobile et moto', 'Voitures de série, de course et motos.', true, []],
            ['Figurines historiques et bustes', 'Figurines de grande échelle et bustes.', true, []],
            ['Science-fiction et mecha', 'Gunpla, vaisseaux spatiaux et mechas.', true, []],
            ['Dioramas et saynètes', 'Mise en scène, décors et compositions.', true, []],
        ]],
        ['Peinture', 'Tout sur la peinture de figurines.', true, [
            ['Techniques de peinture', 'Éclaircissements, lavis, contrast, NMM, OSL…', true, []],
            ['Aérographe', 'Réglages, sous-couches et effets à l\'aérographe.', true, []],
            ['Schémas de couleurs', 'Palettes, inspirations et recettes.', true, []],
            ['Projets et défis de peinture', 'Suivez vos projets en cours et relevez des défis.', true, []],
        ]],
        ['Atelier et modélisme', 'Préparer, assembler et transformer ses figurines.', false, [
            ['Montage et préparation', 'Ébarbage, collage, magnétisation.', true, []],
            ['Conversions et kitbash', 'Transformer et combiner des kits.', true, []],
            ['Socles et décors', 'Soclage, terrains et éléments de décor.', true, []],
            ['Impression 3D', 'Imprimantes, résines, supports et fichiers.', true, []],
            ['Matériel et outils', 'Pinceaux, peintures, outillage et rangement.', true, []],
        ]],
        ['Jeu et compétition', 'Jouer, progresser et s\'affronter.', false, [
            ['Listes d\'armée et tactique', 'Partagez vos listes et discutez stratégie.', true, []],
            ['Rapports de bataille', 'Récits et photos de vos parties.', true, []],
            ['Règles et questions', 'Points de règles, FAQ et errata.', true, []],
            ['Tournois et événements', 'Annonces, inscriptions et comptes rendus.', true, []],
        ]],
        ['Communauté', 'La vie de SprueHub et de ses membres.', false, [
            ['Présentations', 'Présentez-vous à la communauté.', true, []],
            ['Discussion générale', 'Tout ce qui ne rentre pas ailleurs.', true, []],
            ['Bourse aux figurines', 'Achats, ventes et échanges entre membres.', true, []],
            ['Clubs et recherche de joueurs', 'Trouvez des adversaires et des clubs près de chez vous.', true, []],
            ['Suggestions pour SprueHub', 'Vos idées pour améliorer le site.', true, []],
        ]],
    ];

    private int $created = 0;
    private int $existing = 0;
    /** @var array<string, true> */
    private array $usedSlugs = [];

    public function __construct(
        private EntityManagerInterface $em,
        private CategoryRepository $categoryRepository,
        private SluggerInterface $slugger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait créé sans rien enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        foreach ($this->categoryRepository->findAll() as $category) {
            $this->usedSlugs[mb_strtolower($category->getSlug())] = true;
        }

        $this->syncChildren(null, self::TREE, 0, $io, $dryRun);

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('%s%d catégorie(s) créée(s), %d déjà présente(s).', $dryRun ? '[simulation] ' : '', $this->created, $this->existing));

        return Command::SUCCESS;
    }

    /** @param list<array{0: string, 1: string, 2: bool, 3: array}> $definitions */
    private function syncChildren(?Category $parent, array $definitions, int $depth, SymfonyStyle $io, bool $dryRun): void
    {
        $siblings = $parent
            ? ($parent->getId() ? $this->categoryRepository->findBy(['parent' => $parent]) : [])
            : $this->categoryRepository->findBy(['parent' => null]);
        $nextPosition = array_reduce($siblings, fn (int $max, Category $c) => max($max, (int) $c->getPosition()), 0) + 1;

        foreach ($definitions as [$name, $description, $allowThreads, $children]) {
            $category = $this->findByName($siblings, $name);
            $indent = str_repeat('  ', $depth);

            if ($category) {
                $this->existing++;
                $io->writeln(sprintf('%s<fg=gray>= %s (existante)</>', $indent, $category->getName()));
            } else {
                $category = (new Category())
                    ->setName($name)
                    ->setDescription($description)
                    ->setSlug($this->uniqueSlug($name, $parent))
                    ->setPosition($nextPosition++)
                    ->setAllowThreads($allowThreads)
                    ->setParent($parent);
                if (!$dryRun) {
                    $this->em->persist($category);
                }
                $this->created++;
                $io->writeln(sprintf('%s<info>+ %s</info>%s', $indent, $name, $allowThreads ? '' : ' <comment>(regroupement)</comment>'));
            }

            $this->syncChildren($category, $children, $depth + 1, $io, $dryRun);
        }
    }

    /** @param Category[] $siblings */
    private function findByName(array $siblings, string $name): ?Category
    {
        $wanted = $this->normalize($name);
        foreach ($siblings as $sibling) {
            if ($this->normalize($sibling->getName()) === $wanted) {
                return $sibling;
            }
        }

        return null;
    }

    // « Age of sigmar » = « Age of Sigmar », « Leagues of votann » = « Leagues of Votann »…
    private function normalize(string $name): string
    {
        return mb_strtolower($this->slugger->slug($name)->toString());
    }

    private function uniqueSlug(string $name, ?Category $parent): string
    {
        $base = mb_strtolower($this->slugger->slug($name)->toString());
        $slug = $base;
        // Noms répétés à plusieurs endroits (ex. « Chaos » dans 40K et Age of Sigmar) : on préfixe par le parent
        if (isset($this->usedSlugs[$slug]) && $parent) {
            $slug = mb_strtolower($this->slugger->slug($parent->getName())->toString()) . '-' . $base;
        }
        for ($i = 2; isset($this->usedSlugs[$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        $this->usedSlugs[$slug] = true;

        return mb_substr($slug, 0, 100);
    }
}
