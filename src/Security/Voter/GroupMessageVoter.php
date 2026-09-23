<?php

namespace App\Security\Voter;

use App\Entity\GroupChannel;
use App\Entity\GroupMessage;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Épingler / désépingler des messages de channel de groupe.
 *
 * Autorisé si l'utilisateur est membre du groupe avec au moins le rôle choisi par le owner
 * (Group::pinRole) ET peut lire le channel (canRead). Le droit d'écriture n'est pas requis :
 * épingler est de la modération du fil, pas de la publication.
 *
 * Sujet : un GroupMessage (message d'un channel), ou un GroupChannel (« peut épingler dans ce channel »,
 * utile pour les messages ajoutés en temps réel côté client).
 *
 * @extends Voter<string, GroupMessage|GroupChannel>
 */
final class GroupMessageVoter extends Voter
{
    public const PIN = 'GROUP_MESSAGE_PIN';

    public function __construct(private GroupMembershipResolver $membership) {}

    public function supportsAttribute(string $attribute): bool
    {
        return $attribute === self::PIN;
    }

    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, GroupMessage::class, true) || is_a($subjectType, GroupChannel::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::PIN && ($subject instanceof GroupMessage || $subject instanceof GroupChannel);
    }

    /** @param GroupMessage|GroupChannel $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $channel = $subject instanceof GroupMessage ? $subject->getChannel() : $subject;
        $group = $channel?->getUsergroup();
        if (!$channel || !$group) {
            return false;
        }
        // Message rattaché à un autre groupe que son channel : incohérent, refusé
        if ($subject instanceof GroupMessage && $subject->getUsergroup() !== $group) {
            return false;
        }

        $member = $this->membership->getMember($user, $group);

        return $member !== null
            && $member->hasAtLeastRole($group->getPinRole())
            && $member->hasAtLeastRole($channel->getCanRead());
    }
}
