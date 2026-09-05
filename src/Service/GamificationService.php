<?php

namespace App\Service;

use App\Entity\Badge;
use App\Entity\ExperienceAward;
use App\Entity\GamificationActivity;
use App\Entity\Post;
use App\Entity\PostVote;
use App\Entity\Thread;
use App\Entity\GroupInvitation;
use App\Entity\User;
use App\Entity\UserBadge;
use Doctrine\ORM\EntityManagerInterface;

class GamificationService
{
	public function __construct(private EntityManagerInterface $entityManager) {}

	public function getProfileBadges(User $user): array
	{
		$unlocked = [];
		foreach ($this->entityManager->getRepository(UserBadge::class)->findBy(['user' => $user]) as $userBadge) {
			$unlocked[$userBadge->getBadge()->getCode()] = true;
		}

		$connected = ['collector', 'first_upvote', 'popular_10', 'popular_2', 'popular_3', 'prolific_10', 'prolific_2', 'prolific_3', 'master_blacksmith', 'mentor', 'first_thread', 'regular', 'pillar', 'pioneer', 'heroic', 'legendary', 'immortal', 'jurist', 'archaeologist', 'early_bird', 'first_in_class', 'minimalist', 'treasure_hunter', 'night_owl'];
		$badges = array_map(static function (Badge $badge) use ($unlocked, $connected): array {
			return [
				'code' => $badge->getCode(),
				'name' => $badge->getName(),
				'category' => $badge->getCategory(),
				'description' => $badge->getDescription(),
				'hiddenDescription' => $badge->getHiddenDescription() ?: 'Condition secrète à découvrir',
				'icon' => $badge->getIcon(),
				'hidden' => $badge->isHidden(),
				'xpReward' => $badge->getXpReward(),
				'unlocked' => isset($unlocked[$badge->getCode()]),
				'connected' => in_array($badge->getCode(), $connected, true),
			];
		}, $this->entityManager->getRepository(Badge::class)->findBy([], ['category' => 'ASC', 'name' => 'ASC']));

		return $badges;
	}

	public function recordActivity(User $user, string $key): bool
	{
		$repository = $this->entityManager->getRepository(GamificationActivity::class);
		if ($repository->findOneBy(['user' => $user, 'activityKey' => $key])) {
			return false;
		}

		$this->entityManager->persist(new GamificationActivity($user, $key));
		return true;
	}

	public function recordDailyLogin(User $user, ?\DateTimeImmutable $now = null): bool
	{
		$now ??= new \DateTimeImmutable();
		$today = $now->setTime(0, 0);
		$last = $user->getLastDailyLoginAt();
		if ($last && $last >= $today) {
			return false;
		}

		$user->setLoginStreak($last && $last >= $today->modify('-1 day') ? $user->getLoginStreak() + 1 : 1);
		$user->setLastDailyLoginAt($now);
		return true;
	}

	public function syncAllBadges(User $user): bool
	{
		$changed = $this->syncLevelBadges($user);
		$positiveVotes = (int) $this->entityManager->createQueryBuilder()
			->select('COUNT(v.id)')->from(PostVote::class, 'v')->join('v.post', 'p')
			->where('p.author = :user')->andWhere('v.type = :type')->setParameter('user', $user)->setParameter('type', PostVote::TYPE_POSITIVE)->getQuery()->getSingleScalarResult();
		$helpfulVotes = (int) $this->entityManager->createQueryBuilder()
			->select('COUNT(v.id)')->from(PostVote::class, 'v')->join('v.post', 'p')
			->where('p.author = :user')->andWhere('v.type = :type')->setParameter('user', $user)->setParameter('type', PostVote::TYPE_HELPFUL)->getQuery()->getSingleScalarResult();
		$postCount = $this->entityManager->getRepository(Post::class)->count(['author' => $user]);
		$threadCount = $this->entityManager->getRepository(Thread::class)->count(['author' => $user]);
		$masterThreads = count($this->entityManager->createQueryBuilder()->select('t.id')->from(Thread::class, 't')->join('t.posts', 'rp')->where('t.author = :user')->andWhere('rp.author != :user')->setParameter('user', $user)->groupBy('t.id')->having('COUNT(rp.id) >= 10')->getQuery()->getResult());
		$requiredRoutes = ['app_home', 'app_forum_index', 'app_group_index', 'app_todo_index', 'app_army_index', 'app_friendship_list', 'app_message_index', 'app_legal', 'app_terms'];
		$visitedRoutes = 0;
		foreach ($requiredRoutes as $route) {
			$visitedRoutes += $this->hasActivity($user, 'visit:' . $route) ? 1 : 0;
		}
		$bioEmptyForMonth = !$user->getBio() && $user->getCreatedAt() <= new \DateTimeImmutable('-1 month');
		$firstThousand = (int) $this->entityManager->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->where('u.createdAt < :createdAt')->setParameter('createdAt', $user->getCreatedAt())->getQuery()->getSingleScalarResult() < 1000;

		$rules = [
			['first_upvote', $positiveVotes >= 1],
			['popular_10', $positiveVotes >= 10],
			['popular_2', $positiveVotes >= 50],
			['popular_3', $positiveVotes >= 100],
			['prolific_10', $threadCount >= 10],
			['prolific_2', $threadCount >= 50],
			['prolific_3', $threadCount >= 100],
			['master_blacksmith', $masterThreads >= 10],
			['mentor', $helpfulVotes >= 5],
			['first_thread', $threadCount >= 1],
			['collector', $this->entityManager->getRepository(UserBadge::class)->count(['user' => $user]) >= 10],
			['treasure_hunter', $visitedRoutes === count($requiredRoutes)],
			['regular', $user->getLoginStreak() >= 7],
			['pillar', $user->getLoginStreak() >= 30],
			['pioneer', $firstThousand],
			['jurist', $this->hasActivity($user, 'jurist')],
			['archaeologist', $this->hasActivity($user, 'archaeologist')],
			['early_bird', $this->hasActivity($user, 'early_bird')],
			['night_owl', $this->hasActivity($user, 'night_owl')],
			['first_in_class', $this->hasActivity($user, 'first_in_class')],
			['minimalist', $bioEmptyForMonth],
		];

		foreach ($rules as [$code, $eligible]) {
			if (!$eligible || $this->hasBadge($user, $code)) continue;
			$badge = $this->entityManager->getRepository(Badge::class)->findOneBy(['code' => $code]);
			if (!$badge) continue;
			$this->entityManager->persist(new UserBadge($user, $badge));
			$user->addExperience($badge->getXpReward());
			$this->entityManager->persist(new ExperienceAward($user, 'badge:' . $code, $badge->getXpReward()));
			$changed = true;
		}

		return $changed;
	}

	private function hasBadge(User $user, string $code): bool
	{
		return (bool) $this->entityManager->createQueryBuilder()->select('COUNT(ub.id)')->from(UserBadge::class, 'ub')->join('ub.badge', 'b')->where('ub.user = :user')->andWhere('b.code = :code')->setParameter('user', $user)->setParameter('code', $code)->getQuery()->getSingleScalarResult();
	}

	private function hasActivity(User $user, string $key): bool
	{
		return (bool) $this->entityManager->getRepository(GamificationActivity::class)->findOneBy(['user' => $user, 'activityKey' => $key]);
	}

	public function syncLevelBadges(User $user): bool
	{
		$unlocked = [];
		foreach ($this->entityManager->getRepository(UserBadge::class)->findBy(['user' => $user]) as $userBadge) {
			$unlocked[$userBadge->getBadge()->getCode()] = true;
		}

		$levels = [
			['heroic', 10],
			['legendary', 25],
			['immortal', 50],
		];
		$changed = false;
		foreach ($levels as [$code, $level]) {
			if ($user->getLevel() < $level || isset($unlocked[$code])) {
				continue;
			}

			$badge = $this->entityManager->getRepository(Badge::class)->findOneBy(['code' => $code]);
			if (!$badge) {
				continue;
			}

			$this->entityManager->persist(new UserBadge($user, $badge));
			$user->addExperience($badge->getXpReward());
			$this->entityManager->persist(new ExperienceAward($user, 'badge:' . $code, $badge->getXpReward()));
			$changed = true;
		}

		return $changed;
	}
}
