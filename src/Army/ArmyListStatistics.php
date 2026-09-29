<?php

namespace App\Army;

use App\Entity\ArmyList;
use App\Entity\User;
use App\Service\AtomicCounter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records the audience of army lists (views, exports, duplications) by other visitors than the owner.
 * The column is incremented by an atomic UPDATE (App\Service\AtomicCounter: no lost update between concurrent
 * visitors) and the loaded entity is kept in step for the current response.
 */
final class ArmyListStatistics
{
    private const SESSION_PREFIX = 'army_list_counted_';

    public function __construct(
        private readonly AtomicCounter $atomicCounter,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function record(ArmyList $armyList, ArmyListCounter $counter): void
    {
        $visitor = $this->security->getUser();
        if ($visitor instanceof User && $visitor->getId() === $armyList->getOwner()?->getId()) {
            return;
        }
        if ($counter->isCountedOncePerSession() && !$this->markCountedInSession($armyList, $counter)) {
            return;
        }
        $this->atomicCounter->increment($armyList, $counter->property());
    }

    /** True when the counter was not yet counted for this list in the visitor's session (and marks it). */
    private function markCountedInSession(ArmyList $armyList, ArmyListCounter $counter): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return true;
        }
        $session = $request->getSession();
        $key = self::SESSION_PREFIX . $counter->value . '_' . $armyList->getId();
        if ($session->has($key)) {
            return false;
        }
        $session->set($key, true);
        return true;
    }
}
