<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Increments an integer column of an entity with an atomic UPDATE (no lost update between concurrent requests),
 * then keeps the loaded entity in step for the current response. The new value is also recorded as the original
 * value known to the ORM, so a later flush never writes the in-memory value back over concurrent increments.
 */
final class AtomicCounter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function increment(object $entity, string $field, int $step = 1): void
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        $identifier = $metadata->getSingleIdentifierFieldName();
        $this->entityManager->createQueryBuilder()
            ->update($metadata->getName(), 'counted')
            ->set('counted.' . $field, 'counted.' . $field . ' + :step')
            ->where('counted.' . $identifier . ' = :id')
            ->setParameter('step', $step)
            ->setParameter('id', $metadata->getFieldValue($entity, $identifier))
            ->getQuery()
            ->execute();
        $value = (int) $metadata->getFieldValue($entity, $field) + $step;
        $metadata->setFieldValue($entity, $field, $value);
        if ($this->entityManager->contains($entity)) {
            $this->entityManager->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($entity), $field, $value);
        }
    }
}
