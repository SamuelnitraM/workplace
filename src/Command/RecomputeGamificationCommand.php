<?php

namespace App\Command;

use App\Entity\User;
use App\Gamification\BadgeCatalog;
use App\Repository\UserRepository;
use App\Service\GamificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Réévalue la gamification (rattrapage / réparation), pour tous les utilisateurs ou un seul :
 *  1. connexion quotidienne du dernier jour connu non créditée (connexions antérieures au système d'XP) ;
 *  2. badges obtenus sans ligne « badge:<code> » dans le grand livre ;
 *  3. toutes les règles de badges (les membres obtiennent les badges qu'ils méritent déjà) ;
 *  4. avec --resum : user.experience = somme du grand livre experience_award.
 * Idempotente : peut être relancée sans risque de double XP.
 */
#[AsCommand(
    name: 'app:gamification:recompute',
    description: 'Réévalue badges et XP (rattrapage idempotent) ; --resum aligne l\'XP sur le grand livre',
)]
class RecomputeGamificationCommand extends Command
{
    public function __construct(
        private readonly GamificationService $gamification,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Pseudo ou identifiant d\'un utilisateur')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait attribué sans rien écrire')
            ->addOption('resum', null, InputOption::VALUE_NONE, 'Recalcule user.experience comme la somme du grand livre');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $resum = (bool) $input->getOption('resum');

        $selector = $input->getOption('user');
        if ($selector !== null) {
            $user = ctype_digit((string) $selector) ? $this->users->find((int) $selector) : $this->users->findOneBy(['username' => $selector]);
            if (!$user) {
                $io->error(sprintf('Utilisateur « %s » introuvable.', $selector));

                return Command::FAILURE;
            }
            $ids = [$user->getId()];
        } else {
            $ids = array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM `user` ORDER BY id'));
        }

        $rows = [];
        foreach ($ids as $id) {
            $user = $this->users->find($id);
            if (!$user instanceof User) {
                continue;
            }
            $rows[] = $dryRun ? $this->simulate($user, $resum) : $this->apply($user, $resum);
            // Mémoire constante sur de gros volumes
            $this->entityManager->clear();
            $this->gamification->clearCache();
        }

        $io->table(['Utilisateur', 'Connexion rattrapée', 'Badges obtenus', 'XP avant', 'XP après', 'Niveau'], $rows);
        $io->success(($dryRun ? '[simulation] ' : '') . sprintf('%d utilisateur(s) traité(s).', count($rows)));

        return Command::SUCCESS;
    }

    private function apply(User $user, bool $resum): array
    {
        $before = $user->getExperience();
        $daily = $this->gamification->backfillLastDailyLogin($user);
        $repaired = $this->gamification->repairBadgeAwards($user);
        $grantedBefore = array_keys($this->gamification->grantedBadges($user));
        $this->gamification->syncAllBadges($user);
        $obtained = array_values(array_diff(array_keys($this->gamification->grantedBadges($user)), $grantedBefore));
        if ($resum) {
            $this->gamification->resetExperienceToLedger($user);
        }

        return [
            $user->getUsername(),
            $daily > 0 ? '+' . $daily . ' XP' : '—',
            implode(', ', array_merge($obtained, array_map(static fn ($code) => $code . ' (XP réparée)', $repaired))) ?: '—',
            $before,
            $user->getExperience(),
            $user->getLevel(),
        ];
    }

    private function simulate(User $user, bool $resum): array
    {
        $before = $user->getExperience();
        $base = $resum ? $this->gamification->ledgerTotal($user) : $before;
        $daily = $this->gamification->backfillLastDailyLogin($user, true);
        $repaired = $this->gamification->repairBadgeAwards($user, true);
        $repairedXp = array_sum(array_map(static fn ($code) => BadgeCatalog::get($code)['xp'] ?? 0, $repaired));
        $codes = $this->gamification->simulateBadges($user, $base - $before + $daily + $repairedXp);
        $after = $base + $daily + $repairedXp + array_sum(array_map(static fn ($code) => BadgeCatalog::get($code)['xp'], $codes));

        return [
            $user->getUsername(),
            $daily > 0 ? '+' . $daily . ' XP' : '—',
            implode(', ', $codes) ?: '—',
            $before,
            $after,
            (new User())->setExperience($after)->getLevel(),
        ];
    }
}
