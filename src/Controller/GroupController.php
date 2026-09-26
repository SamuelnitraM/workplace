<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\GroupChannel;
use App\Entity\GroupMember;
use App\Entity\GroupMessage;
use App\Entity\Notification;
use App\Entity\User;
use App\Gamification\UserTitleManager;
use App\Group\GroupDirectory;
use App\Repository\FriendshipRepository;
use App\Repository\GroupInvitationRepository;
use App\Repository\TodoNodeRepository;
use App\Text\MentionResolver;
use App\Repository\GroupChannelRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupMessageRepository;
use App\Repository\GroupRepository;
use App\Repository\NotificationRepository;
use App\Security\Voter\GroupChannelVoter;
use App\Security\Voter\GroupMembershipResolver;
use App\Security\Voter\GroupMessageVoter;
use App\Security\Voter\GroupVoter;
use App\Service\NotificationService;
use App\Service\PusherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/groups', name: 'app_group_')]
class GroupController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'group';
    private const NAME_MAX_LENGTH = 100;
    private const MESSAGE_MAX_LENGTH = 2000;
    private const MAX_PINNED_PER_CHANNEL = 20;

    public function __construct(
        private GroupRepository $groupRepository,
        private GroupMemberRepository $groupMemberRepository,
        private GroupMembershipResolver $membership,
        private NotificationRepository $notificationRepository,
    ) {}

    /**
     * Groups page. Member: received invitations (left), their groups ordered by activity or by their own order
     * (centre), suggestions (right). Visitor: the public groups.
     */
    #[Route('/', name: 'index')]
    public function index(GroupDirectory $directory, GroupInvitationRepository $invitationRepository): Response
    {
        if (!$this->getUser()) {
            return $this->render('group/index.html.twig', [
                'publicGroups' => $this->groupRepository->findPublicGroupsNotMember(),
            ]);
        }
        $user = $this->currentUser();
        // The pending invitations are shown on this page: their notifications are read
        $this->notificationRepository->markReadByTypes($user, [Notification::TYPE_GROUP_INVITATION]);
        return $this->render('group/index.html.twig', [
            'myGroups' => $directory->groupsOf($user),
            'invitations' => $invitationRepository->findPendingFor($user),
            'suggestions' => $directory->suggestionsFor($user),
            'unreadGroupCounts' => $this->unreadGroupCounts($user),
            'sortMode' => $user->getGroupSortMode(),
        ]);
    }

    /** Every public group (discovery beyond the suggestions). */
    #[Route('/publics', name: 'public', methods: ['GET'])]
    public function publicGroups(): Response
    {
        $myGroupIds = $this->getUser() ? array_map(static fn (Group $group) => $group->getId(), $this->groupRepository->findGroupsByMember($this->currentUser())) : [];
        return $this->render('group/public.html.twig', [
            'publicGroups' => $this->groupRepository->findPublicGroupsNotMember($myGroupIds),
        ]);
    }

    /** Switch of the groups page order: most recent activity, or the member's own order. */
    #[Route('/tri', name: 'sort_mode', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sortMode(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessCsrfValid($request);
        $this->currentUser()->setGroupSortMode($request->request->getString('mode'));
        $em->flush();
        return $this->redirectToRoute('app_group_index');
    }

    /** Member's own order of their groups (drag and drop of the groups page), as a list of group ids. */
    #[Route('/ordre', name: 'order', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function order(Request $request, GroupDirectory $directory): JsonResponse
    {
        $this->denyUnlessCsrfValid($request);
        $directory->saveOrder($this->currentUser(), array_map('intval', (array) $request->request->all('ids')));
        return new JsonResponse(['saved' => true]);
    }

    /**
     * Unread messages of each group (unread "group_message" notifications).
     *
     * @return array<int, int>
     */
    private function unreadGroupCounts(User $user): array
    {
        $unreadGroupCounts = [];
        foreach ($this->notificationRepository->sumUnreadCountsByGroupKey($user, Notification::TYPE_GROUP_MESSAGE, 'group:') as $groupKey => $count) {
            if (preg_match('/^group:(\d+):/', $groupKey, $matches)) {
                $unreadGroupCounts[(int) $matches[1]] = ($unreadGroupCounts[(int) $matches[1]] ?? 0) + $count;
            }
        }
        return $unreadGroupCounts;
    }

    // Create a group
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request);
            $user = $this->currentUser();

            $name = trim($request->request->getString('name'));
            $error = $this->validateName($name);
            if ($error) {
                $this->addFlash('error', $error);
                return $this->render('group/new.html.twig', [
                    'name' => $name,
                    'description' => $request->request->getString('description'),
                ]);
            }

            $group = new Group();
            $group->setName($name);
            $group->setDescription(trim($request->request->getString('description')) ?: null);
            $group->setSlug($this->makeSlug($slugger, $name));
            $group->setIsPublic($request->request->get('isPublic') === '1');
            $group->setIsJoinable($request->request->get('isJoinable') === '1');
            $group->setCreator($user);

            // The creator automatically becomes owner
            $member = new GroupMember();
            $member->setUser($user);
            $member->setUsergroup($group);
            $member->setRole('owner');

            $em->persist($group);
            $em->persist($member);
            $em->flush();

            $this->addFlash('success', 'Groupe créé avec succès !');
            return $this->redirectToRoute('app_group_show', ['slug' => $group->getSlug()]);
        }

        // Optional pre-filled name (e.g. guided tour: "Create your faction's first group")
        return $this->render('group/new.html.twig', [
            'name' => mb_substr(trim($request->query->getString('name')), 0, 100),
        ]);
    }

    // Group page
    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(
        string $slug,
        Request $request,
        GroupChannelRepository $groupChannelRepository,
        GroupMessageRepository $groupMessageRepository,
        FriendshipRepository $friendshipRepository,
        GroupInvitationRepository $invitationRepository,
        TodoNodeRepository $todoNodeRepository,
    ): Response {
        $group = $this->findGroup($slug);

        // Private group: only members can view it (anonymous visitor -> login page)
        if (!$this->isGranted(GroupVoter::VIEW, $group)) {
            if (!$this->getUser()) {
                return $this->redirectToRoute('app_login');
            }
            $this->addFlash('error', 'Ce groupe est privé.');
            return $this->redirectToRoute('app_group_index');
        }

        $currentMember = $this->findMember($group);

        // Channels accessible according to the role
        $channels = [];
        $activeChannel = null;

        if ($currentMember) {
            foreach ($group->getChannels() as $channel) {
                if ($this->isGranted(GroupChannelVoter::READ, $channel)) {
                    $channels[] = $channel;
                }
            }

            // Active channel = the requested one (if readable) or the first one
            $channelId = $request->query->getInt('channel');
            if ($channelId) {
                $requested = $groupChannelRepository->find($channelId);
                if ($requested && in_array($requested, $channels, true)) {
                    $activeChannel = $requested;
                }
            }

            if (!$activeChannel && !empty($channels)) {
                $activeChannel = $channels[0];
            }
        }

        // Unread messages per channel; those of the displayed channel are marked as read
        $unreadChannelCounts = [];
        if ($currentMember) {
            $prefix = self::channelNotificationKeyPrefix($group);
            foreach ($this->notificationRepository->sumUnreadCountsByGroupKey($this->currentUser(), Notification::TYPE_GROUP_MESSAGE, $prefix) as $groupKey => $count) {
                $unreadChannelCounts[(int) substr($groupKey, strlen($prefix))] = $count;
            }
            if ($activeChannel) {
                $this->notificationRepository->markReadByGroupKey($this->currentUser(), self::channelNotificationKey($activeChannel));
                unset($unreadChannelCounts[$activeChannel->getId()]);
            }
        }

        $pinnedMessages = $activeChannel ? $groupMessageRepository->findPinnedByChannel($activeChannel) : [];
        $user = $this->getUser() instanceof User ? $this->currentUser() : null;

        return $this->render('group/show.html.twig', [
            'group' => $group,
            'currentMember' => $currentMember,
            'channels' => $channels,
            'activeChannel' => $activeChannel,
            // Authors and titles loaded with the messages (single query)
            'messages' => $activeChannel ? $groupMessageRepository->findByChannelWithAuthors($activeChannel) : [],
            'unreadChannelCounts' => $unreadChannelCounts,
            'channelNotificationKey' => $activeChannel ? self::channelNotificationKey($activeChannel) : null,
            // Pinned messages: dedicated query (visible even when far back in the history)
            'pinnedMessages' => $pinnedMessages,
            'pinnedData' => array_map(fn (GroupMessage $m) => $this->serializePinnedMessage($m), $pinnedMessages),
            'canPin' => $activeChannel && $this->isGranted(GroupMessageVoter::PIN, $activeChannel),
            // Invitation window: friends who are neither members nor already invited
            'inviteCandidates' => $user !== null && $this->isGranted(GroupVoter::INVITE, $group) ? $this->inviteCandidates($group, $user, $friendshipRepository, $invitationRepository) : [],
            // Right column: progress of the to-do lists the member can see
            'todoLists' => $currentMember ? $todoNodeRepository->findGroupLists($group, $this->isGranted(GroupVoter::TODO_VIEW_ALL, $group) ? null : $user) : [],
        ]);
    }

    // Join a group
    #[Route('/{slug}/join', name: 'join', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function join(
        string $slug,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyUnlessCsrfValid($request);
        $group = $this->findGroup($slug);

        if ($this->isGranted(GroupVoter::MEMBER, $group)) {
            $this->addFlash('error', 'Vous êtes déjà membre de ce groupe.');
            return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
        }

        // A private group can only be joined through an invitation
        if (!$this->isGranted(GroupVoter::JOIN, $group)) {
            $this->addFlash('error', 'Ce groupe n\'accepte pas de nouvelles demandes.');
            return $this->redirectToRoute('app_group_index');
        }

        $member = new GroupMember();
        $member->setUser($this->currentUser());
        $member->setUsergroup($group);
        $member->setRole('member');

        $em->persist($member);
        $em->flush();

        $this->addFlash('success', 'Vous avez rejoint le groupe !');
        return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
    }

    // Leave a group
    #[Route('/{slug}/leave', name: 'leave', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function leave(
        string $slug,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyUnlessCsrfValid($request);
        $group = $this->findGroup($slug);
        $member = $this->findMember($group);

        if (!$member) {
            $this->addFlash('error', 'Vous n\'êtes pas membre de ce groupe.');
            return $this->redirectToRoute('app_group_index');
        }

        if ($this->isGranted(GroupVoter::OWNER, $group)) {
            $this->addFlash('error', 'Le propriétaire ne peut pas quitter le groupe : supprimez-le depuis ses paramètres si besoin.');
            return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
        }

        $em->remove($member);
        $em->flush();
        // Unread messages of a group the user left are not accessible
        $this->notificationRepository->markReadByGroupKeyPrefix($this->currentUser(), self::channelNotificationKeyPrefix($group));

        $this->addFlash('success', 'Vous avez quitté le groupe.');
        return $this->redirectToRoute('app_group_index');
    }

    // Send a message in the chat
    #[Route('/{slug}/message', name: 'message', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function message(
        string $slug,
        Request $request,
        GroupChannelRepository $groupChannelRepository,
        EntityManagerInterface $em,
        PusherService $pusher,
        NotificationService $notifications,
        MentionResolver $mentionResolver,
    ): Response {
        $this->denyUnlessCsrfValid($request);
        $user = $this->currentUser();
        $group = $this->findGroup($slug);
        $this->denyAccessUnlessGranted(GroupVoter::MEMBER, $group);

        $channelId = $request->request->getInt('channel_id');
        $channel = $channelId ? $groupChannelRepository->find($channelId) : null;

        if (!$channel || $channel->getUsergroup() !== $group) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(GroupChannelVoter::WRITE, $channel);

        $redirect = $this->redirectToRoute('app_group_show', ['slug' => $slug, 'channel' => $channelId]);

        $content = trim($request->request->getString('content'));
        if ($content === '' || mb_strlen($content) > self::MESSAGE_MAX_LENGTH) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Message vide ou trop long.'], Response::HTTP_BAD_REQUEST);
            }
            return $redirect;
        }

        $message = new GroupMessage();
        $message->setContent($content);
        $message->setAuthor($user);
        $message->setUsergroup($group);
        $message->setChannel($channel);

        $em->persist($message);
        $em->flush();

        $payload = [
            'id' => $message->getId(),
            'content' => $message->getContent(),
            // Escaped content, @pseudo of existing members as profile links (chat_controller.js)
            'contentHtml' => $mentionResolver->linkify($content),
            'author' => $user->getUsername(),
            'authorId' => $user->getId(),
            'avatar' => $user->getAvatar(),
            'createdAt' => $message->getCreatedAt()->format('d/m H:i'),
            // Author's title ({name, tier, icon} or null), rendered by chat_controller.js (textContent)
            'title' => UserTitleManager::payload($user),
        ];

        // Real time in the channel (private channel: subscription authorized via /pusher/auth)
        $pusher->sendMessage(PusherService::groupChannel($channel->getId()), 'new-message', $payload);

        // Mentioned members who can read the channel get a mention notification, even when they muted the group;
        // the other readers get the message notification (aggregated per channel), unless they muted the group
        $mentioned = array_map('mb_strtolower', MentionResolver::extract($content));
        $mentionRecipients = [];
        $recipients = [];
        foreach ($group->getMembers() as $member) {
            if ($member->getUser() === $user || !$member->hasAtLeastRole($channel->getCanRead())) {
                continue;
            }
            if (in_array(mb_strtolower((string) $member->getUser()->getUsername()), $mentioned, true)) {
                $mentionRecipients[] = $member->getUser();
            } elseif (!$member->isMuted()) {
                $recipients[] = $member->getUser();
            }
        }
        $channelUrl = $this->generateUrl('app_group_show', ['slug' => $group->getSlug(), 'channel' => $channel->getId()]);
        $notifications->notifyMany(
            $mentionRecipients,
            Notification::TYPE_GROUP_MENTION,
            $user,
            ['group' => $group->getName(), 'groupId' => $group->getId(), 'channel' => $channel->getName(), 'channelId' => $channel->getId()],
            $channelUrl . '#msg-' . $message->getId(),
            'group_mention:' . $channel->getId(),
        );
        $notifications->notifyMany(
            $recipients,
            Notification::TYPE_GROUP_MESSAGE,
            $user,
            [
                'group' => $group->getName(),
                'groupId' => $group->getId(),
                'channel' => $channel->getName(),
                'channelId' => $channel->getId(),
            ],
            $this->generateUrl('app_group_show', ['slug' => $group->getSlug(), 'channel' => $channel->getId()]),
            self::channelNotificationKey($channel),
        );

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse($payload);
        }

        return $redirect;
    }

    /** Mutes the group for the current member (no message notification, mentions excepted), or unmutes it. */
    #[Route('/{slug}/sourdine', name: 'mute', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function mute(string $slug, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessCsrfValid($request);
        $group = $this->findGroup($slug);
        $member = $this->findMember($group) ?? throw $this->createAccessDeniedException();
        $member->setMuted($request->request->getBoolean('muted'));
        $em->flush();
        $this->addFlash('success', $member->isMuted()
            ? 'Groupe en sourdine : vous ne serez notifié que si l\'on vous mentionne.'
            : 'Les notifications du groupe sont réactivées.');
        return $this->redirectToRoute('app_group_show', ['slug' => $slug, 'channel' => $request->request->getInt('channel') ?: null]);
    }

    // Pin a message of a channel
    #[Route('/{slug}/message/{id}/pin', name: 'message_pin', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function pinMessage(
        string $slug,
        int $id,
        Request $request,
        GroupMessageRepository $groupMessageRepository,
        EntityManagerInterface $em,
        PusherService $pusher,
    ): Response {
        return $this->setMessagePinned(true, $slug, $id, $request, $groupMessageRepository, $em, $pusher);
    }

    // Unpin a message of a channel
    #[Route('/{slug}/message/{id}/unpin', name: 'message_unpin', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function unpinMessage(
        string $slug,
        int $id,
        Request $request,
        GroupMessageRepository $groupMessageRepository,
        EntityManagerInterface $em,
        PusherService $pusher,
    ): Response {
        return $this->setMessagePinned(false, $slug, $id, $request, $groupMessageRepository, $em, $pusher);
    }

    #[Route('/{slug}/edit', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        string $slug,
        Request $request,
        GroupChannelRepository $groupChannelRepository,
        EntityManagerInterface $em
    ): Response {
        $group = $this->findGroup($slug);
        $this->denyAccessUnlessGranted(GroupVoter::MANAGE, $group);

        if (!$request->isMethod('POST')) {
            return $this->render('group/edit.html.twig', [
                'group' => $group,
                'currentMember' => $this->findMember($group),
            ]);
        }

        $this->denyUnlessCsrfValid($request);
        $action = $request->request->getString('action');
        $isOwner = $this->isGranted(GroupVoter::OWNER, $group);
        $redirect = $this->redirectToRoute('app_group_edit', ['slug' => $group->getSlug()]);

        // General information form
        if ($action === 'update_info') {
            $name = trim($request->request->getString('name'));
            $error = $this->validateName($name);
            if ($error) {
                $this->addFlash('error', $error);
                return $redirect;
            }

            $group->setName($name);
            $group->setDescription(trim($request->request->getString('description')) ?: null);
            $group->setIsPublic($request->request->get('isPublic') === '1');
            $group->setIsJoinable($request->request->get('isJoinable') === '1');
            $inviteRole = $request->request->getString('invite_role', $group->getInviteRole());
            if ($this->isValidRole($inviteRole)) {
                $group->setInviteRole($inviteRole);
            }
            $em->flush();

            $this->addFlash('success', 'Paramètres mis à jour !');
            return $redirect;
        }

        // Role change (owner only)
        if ($action === 'update_role' && $isOwner) {
            $memberId = $request->request->getInt('member_id');
            $newRole = $request->request->getString('role');
            $targetMember = $memberId ? $this->groupMemberRepository->find($memberId) : null;

            if ($targetMember && $targetMember->getUsergroup() === $group
                && $targetMember->getRole() !== 'owner'
                && in_array($newRole, ['admin', 'member'], true)) {
                $targetMember->setRole($newRole);
                $em->flush();
                $this->addFlash('success', 'Rôle mis à jour !');
            }

            return $redirect;
        }

        // Create a channel (owner only)
        if ($action === 'create_channel' && $isOwner) {
            $channelName = trim($request->request->getString('channel_name'));
            $canRead = $request->request->getString('channel_can_read', 'member');
            $canWrite = $request->request->getString('channel_can_write', 'member');

            if ($channelName === '' || mb_strlen($channelName) > 50 || !$this->isValidRole($canRead) || !$this->isValidRole($canWrite)) {
                $this->addFlash('error', 'Nom de salon ou droits invalides (50 caractères maximum).');
                return $redirect;
            }

            $channel = new GroupChannel();
            $channel->setName($channelName);
            $channel->setUsergroup($group);
            $channel->setCanRead($canRead);
            $channel->setCanWrite($canWrite);

            // Position = last + 1
            $maxPosition = -1;
            foreach ($group->getChannels() as $existing) {
                $maxPosition = max($maxPosition, $existing->getPosition());
            }
            $channel->setPosition($maxPosition + 1);

            $em->persist($channel);
            $em->flush();
            $this->addFlash('success', 'Salon créé !');

            return $redirect;
        }

        // Edit the permissions of a channel (owner and admins)
        if ($action === 'update_channel') {
            $channelId = $request->request->getInt('channel_id');
            $channel = $channelId ? $groupChannelRepository->find($channelId) : null;
            $canRead = $request->request->getString('can_read', 'member');
            $canWrite = $request->request->getString('can_write', 'member');

            if ($channel && $channel->getUsergroup() === $group
                && $this->isValidRole($canRead) && $this->isValidRole($canWrite)) {
                $channel->setCanRead($canRead);
                $channel->setCanWrite($canWrite);
                $em->flush();
                $this->addFlash('success', 'Droits mis à jour !');
            }

            return $redirect;
        }

        // To-do settings: minimum roles to write and to view everything (owner only)
        if ($action === 'update_todo_settings' && $isOwner) {
            $todoWriteRole = $request->request->getString('todo_write_role');
            $todoViewRole = $request->request->getString('todo_view_role');
            $assignmentRole = $request->request->getString('assignment_role', $group->getAssignmentRole());
            if (!$this->isValidRole($todoWriteRole) || !$this->isValidRole($todoViewRole) || !$this->isValidRole($assignmentRole)) {
                $this->addFlash('error', 'Rôle invalide.');
                return $redirect;
            }

            $group->setTodoWriteRole($todoWriteRole);
            $group->setTodoViewRole($todoViewRole);
            $group->setAssignmentRole($assignmentRole);
            $group->setMaxAssigneesPerTask($request->request->getInt('max_assignees', $group->getMaxAssigneesPerTask()));
            $em->flush();
            $this->addFlash('success', 'Paramètres des tâches mis à jour !');

            return $redirect;
        }

        // Pinned messages: minimum role to pin (owner only)
        if ($action === 'update_pin_settings' && $isOwner) {
            $pinRole = $request->request->getString('pin_role');
            if (!$this->isValidRole($pinRole)) {
                $this->addFlash('error', 'Rôle invalide.');
                return $redirect;
            }

            $group->setPinRole($pinRole);
            $em->flush();
            $this->addFlash('success', 'Paramètres des messages épinglés mis à jour !');

            return $redirect;
        }

        throw $this->createAccessDeniedException();
    }

    // Remove a member
    #[Route('/{slug}/kick/{memberId}', name: 'kick', methods: ['POST'], requirements: ['memberId' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function kick(
        string $slug,
        int $memberId,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyUnlessCsrfValid($request);
        $group = $this->findGroup($slug);
        $this->denyAccessUnlessGranted(GroupVoter::OWNER, $group);

        $targetMember = $this->groupMemberRepository->find($memberId);
        if ($targetMember && $targetMember->getUsergroup() === $group
            && $targetMember->getRole() !== 'owner') {
            $em->remove($targetMember);
            $em->flush();
            $this->addFlash('success', 'Membre exclu du groupe.');
        }

        return $this->redirectToRoute('app_group_edit', ['slug' => $slug]);
    }

    // Delete the group
    #[Route('/{slug}/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        string $slug,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyUnlessCsrfValid($request);
        $group = $this->findGroup($slug);
        $this->denyAccessUnlessGranted(GroupVoter::OWNER, $group);

        $em->remove($group);
        $em->flush();

        $this->addFlash('success', 'Groupe supprimé.');
        return $this->redirectToRoute('app_group_index');
    }

    #[Route('/{slug}/channel/{channelId}/delete', name: 'channel_delete', methods: ['POST'], requirements: ['channelId' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function channelDelete(
        string $slug,
        int $channelId,
        Request $request,
        GroupChannelRepository $groupChannelRepository,
        EntityManagerInterface $em
    ): Response {
        $this->denyUnlessCsrfValid($request);
        $group = $this->findGroup($slug);
        $this->denyAccessUnlessGranted(GroupVoter::OWNER, $group);

        $channel = $groupChannelRepository->find($channelId);
        if ($channel && $channel->getUsergroup() === $group) {
            $em->remove($channel);
            $em->flush();
            $this->addFlash('success', 'Salon supprimé.');
        }

        return $this->redirectToRoute('app_group_edit', ['slug' => $slug]);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Pins / unpins a message (idempotent): the message must belong to a channel of this group
     * and the user must hold the GroupMessageVoter::PIN right. Broadcasts "message-pinned" / "message-unpinned"
     * on the channel's Pusher channel. JSON response for fetch, redirect (with flash message) otherwise.
     */
    private function setMessagePinned(
        bool $pin,
        string $slug,
        int $id,
        Request $request,
        GroupMessageRepository $groupMessageRepository,
        EntityManagerInterface $em,
        PusherService $pusher,
    ): Response {
        $this->denyUnlessCsrfValid($request);
        $group = $this->findGroup($slug);

        $message = $groupMessageRepository->find($id);
        $channel = $message?->getChannel();
        if (!$message || !$channel || $channel->getUsergroup() !== $group || $message->getUsergroup() !== $group) {
            throw $this->createNotFoundException('Message introuvable');
        }

        $this->denyAccessUnlessGranted(GroupMessageVoter::PIN, $message);

        $isAjax = $request->isXmlHttpRequest();
        $redirect = $this->redirectToRoute('app_group_show', ['slug' => $group->getSlug(), 'channel' => $channel->getId()]);

        if ($pin && !$message->isPinned()) {
            if ($groupMessageRepository->countPinnedByChannel($channel) >= self::MAX_PINNED_PER_CHANNEL) {
                $error = sprintf('Ce salon a déjà %d messages épinglés : désépinglez-en un avant d\'en ajouter.', self::MAX_PINNED_PER_CHANNEL);
                if ($isAjax) {
                    return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', $error);
                return $redirect;
            }

            $message->pin($this->currentUser());
            $em->flush();
            $pusher->sendMessage(PusherService::groupChannel($channel->getId()), 'message-pinned', [
                'message' => $this->serializePinnedMessage($message),
            ]);
        } elseif (!$pin && $message->isPinned()) {
            $message->unpin();
            $em->flush();
            $pusher->sendMessage(PusherService::groupChannel($channel->getId()), 'message-unpinned', [
                'id' => $message->getId(),
            ]);
        }

        if ($isAjax) {
            return new JsonResponse([
                'id' => $message->getId(),
                'pinned' => $message->isPinned(),
                'message' => $message->isPinned() ? $this->serializePinnedMessage($message) : null,
            ]);
        }

        $this->addFlash('success', $message->isPinned() ? 'Message épinglé.' : 'Message désépinglé.');
        return $redirect;
    }

    /**
     * Friends of the member who can be invited: not members of the group, not already invited.
     *
     * @return User[]
     */
    private function inviteCandidates(Group $group, User $user, FriendshipRepository $friendshipRepository, GroupInvitationRepository $invitationRepository): array
    {
        $memberIds = array_map(static fn (GroupMember $member) => $member->getUser()->getId(), $group->getMembers()->toArray());
        $invitedIds = array_map(
            static fn ($invitation) => $invitation->getInvitedUser()->getId(),
            $invitationRepository->findBy(['usergroup' => $group, 'status' => 'pending'])
        );
        return array_values(array_filter(
            $friendshipRepository->findFriendsOf($user),
            static fn (User $friend) => !in_array($friend->getId(), [...$memberIds, ...$invitedIds], true),
        ));
    }

    /** Data of a pinned message for the client (pinned messages bar, real time). */
    private function serializePinnedMessage(GroupMessage $message): array
    {
        $author = $message->getAuthor();

        return [
            'id' => $message->getId(),
            'content' => $message->getContent(),
            'author' => $author?->getUsername(),
            'authorId' => $author?->getId(),
            'createdAt' => $message->getCreatedAt()?->format('d/m H:i'),
            'pinnedAt' => $message->getPinnedAt()?->format('d/m H:i'),
            'pinnedAtTs' => $message->getPinnedAt()?->getTimestamp(),
            'pinnedBy' => $message->getPinnedBy()?->getUsername(),
        ];
    }

    /** Aggregation key of a channel's messages: "group:{groupId}:channel:{channelId}". */
    public static function channelNotificationKey(GroupChannel $channel): string
    {
        return self::channelNotificationKeyPrefix($channel->getUsergroup()) . $channel->getId();
    }

    private static function channelNotificationKeyPrefix(Group $group): string
    {
        return 'group:' . $group->getId() . ':channel:';
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function findGroup(string $slug): Group
    {
        $group = $this->groupRepository->findOneBy(['slug' => $slug]);
        if (!$group) {
            throw $this->createNotFoundException('Groupe introuvable');
        }

        return $group;
    }

    /** Membership of the current user (same per-request cache as the voters). */
    private function findMember(Group $group): ?GroupMember
    {
        $user = $this->getUser();

        return $this->membership->getMember($user instanceof User ? $user : null, $group);
    }

    private function denyUnlessCsrfValid(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function validateName(string $name): ?string
    {
        if ($name === '') {
            return 'Le nom du groupe est obligatoire.';
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return sprintf('Le nom du groupe ne doit pas dépasser %d caractères.', self::NAME_MAX_LENGTH);
        }

        return null;
    }

    private function isValidRole(string $role): bool
    {
        return array_key_exists($role, GroupMember::ROLE_LEVELS);
    }

    /** Unique slug fitting in the column (100 characters): 80 + '-' + uniqid (13). */
    private function makeSlug(SluggerInterface $slugger, string $name): string
    {
        $base = trim(mb_substr(strtolower($slugger->slug($name)), 0, 80), '-');

        return ($base !== '' ? $base . '-' : '') . uniqid();
    }
}
