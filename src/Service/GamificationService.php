<?php

namespace App\Service;

use App\Entity\Badge;
use App\Entity\ExperienceAward;
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

		$connected = ['heroic', 'legendary', 'immortal'];
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
