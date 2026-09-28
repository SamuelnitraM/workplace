<?php

namespace App\Account;

use App\Entity\User;
use App\Mailer\TransactionalMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletion of the accounts without activity for the period set in config/packages/legal.yaml (inactivity_years),
 * announced by a warning e-mail inactivity_warning_days before. Any activity clears the warning (PresenceService::touch).
 * The last activity of a member who never came back after the registration is the registration date.
 * Staff accounts and the « Membre supprimé » account are never concerned.
 * Run daily by app:accounts:purge-inactive.
 */
class InactiveAccountPurger
{
    /** @param array{inactivity_years: int, inactivity_warning_days: int} $legal */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountDeleter $accountDeleter,
        private readonly TransactionalMailer $mailer,
        #[Autowire(param: 'app.legal')] private readonly array $legal,
    ) {
    }

    /** @return array{warned: int, deleted: int} */
    public function purge(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $warningDelay = new \DateInterval('P' . $this->legal['inactivity_warning_days'] . 'D');
        $inactiveSince = $now->sub(new \DateInterval('P' . $this->legal['inactivity_years'] . 'Y'));
        $deleted = 0;
        foreach ($this->concernedMembers('account.inactivityWarnedAt <= :warnedBefore', ['warnedBefore' => $now->sub($warningDelay)]) as $member) {
            $this->accountDeleter->delete($member, sprintf('Ton compte a été supprimé après %d ans sans connexion, comme annoncé dans notre e-mail de prévenance.', $this->legal['inactivity_years']));
            $deleted++;
        }
        $warned = 0;
        $criteria = 'account.inactivityWarnedAt IS NULL AND COALESCE(account.lastActivityAt, account.createdAt) < :inactiveBefore';
        foreach ($this->concernedMembers($criteria, ['inactiveBefore' => $inactiveSince->add($warningDelay)]) as $member) {
            $member->setInactivityWarnedAt($now);
            $this->mailer->send($member, 'Ton compte SprueHub va être supprimé', 'email/inactivity_warning.html.twig', [
                'deletionDate' => $now->add($warningDelay),
                'inactivityYears' => $this->legal['inactivity_years'],
            ]);
            $warned++;
        }
        $this->em->flush();
        return ['warned' => $warned, 'deleted' => $deleted];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<User>
     */
    private function concernedMembers(string $criteria, array $parameters): array
    {
        $query = $this->em->getRepository(User::class)->createQueryBuilder('account')
            ->where($criteria)
            ->andWhere('account.email != :deletedMemberEmail')
            ->setParameter('deletedMemberEmail', User::DELETED_MEMBER_EMAIL);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        $members = $query->getQuery()->getResult();
        return array_values(array_filter($members, static fn (User $member): bool => !$member->isStaff()));
    }
}
