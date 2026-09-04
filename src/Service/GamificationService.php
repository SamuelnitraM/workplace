<?php

namespace App\Service;

use App\Entity\Badge;
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

		$badges = array_map(static function (Badge $badge) use ($unlocked): array {
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
			];
		}, $this->entityManager->getRepository(Badge::class)->findBy([], ['category' => 'ASC', 'name' => 'ASC']));

		return $badges;
	}
}
