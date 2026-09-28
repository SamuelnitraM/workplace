<?php

namespace App\Moderation;

use App\Entity\Appeal;
use App\Entity\Report;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Retention of the moderation records (config/packages/legal.yaml, report_retention_months):
 * closed reports and decided appeals are deleted that many months after the decision,
 * except while the member they concern is still suspended or banned, as they justify the sanction.
 * Run daily by app:moderation:purge.
 */
class ModerationRecordPurger
{
    /** @param array{report_retention_months: int} $legal */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(param: 'app.legal')] private readonly array $legal,
    ) {
    }

    /** @return array{reports: int, appeals: int} */
    public function purge(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $decidedBefore = $now->sub(new \DateInterval('P' . $this->legal['report_retention_months'] . 'M'));
        return [
            'reports' => $this->deleteExpired(Report::class, 'targetAuthor', 'record.status = :closed', ['closed' => Report::STATUS_CLOSED], $decidedBefore, $now),
            'appeals' => $this->deleteExpired(Appeal::class, 'member', 'record.status != :pending', ['pending' => Appeal::STATUS_PENDING], $decidedBefore, $now),
        ];
    }

    /**
     * @param class-string $entity
     * @param array<string, mixed> $parameters
     */
    private function deleteExpired(string $entity, string $memberField, string $decidedCriteria, array $parameters, \DateTimeImmutable $decidedBefore, \DateTimeImmutable $now): int
    {
        $query = $this->em->createQueryBuilder()->select('record.id')->from($entity, 'record')
            ->leftJoin('record.' . $memberField, 'targetMember')
            ->where($decidedCriteria)
            ->andWhere('record.handledAt < :decidedBefore')
            ->andWhere('targetMember.id IS NULL OR targetMember.suspendedAt IS NULL OR (targetMember.suspendedUntil IS NOT NULL AND targetMember.suspendedUntil <= :now)')
            ->setParameter('decidedBefore', $decidedBefore)
            ->setParameter('now', $now);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        $expiredIds = array_column($query->getQuery()->getScalarResult(), 'id');
        if ($expiredIds === []) {
            return 0;
        }
        return $this->em->createQueryBuilder()->delete($entity, 'record')
            ->where('record.id IN (:ids)')
            ->setParameter('ids', $expiredIds)
            ->getQuery()->execute();
    }
}
