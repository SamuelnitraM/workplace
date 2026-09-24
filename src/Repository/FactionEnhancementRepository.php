<?php

namespace App\Repository;

use App\Entity\FactionEnhancement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FactionEnhancement>
 */
class FactionEnhancementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FactionEnhancement::class);
    }

    /** @return FactionEnhancement[] */
    public function findForDetachment(string $faction, string $detachment): array
    {
        return $this->findBy(['faction' => $faction, 'detachment' => $detachment], ['name' => 'ASC']);
    }

    /** @return FactionEnhancement[] */
    public function findForFaction(string $faction): array
    {
        return $this->findBy(['faction' => $faction], ['detachment' => 'ASC', 'name' => 'ASC']);
    }
}
