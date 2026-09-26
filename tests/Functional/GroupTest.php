<?php

namespace App\Tests\Functional;

use App\Entity\Friendship;
use App\Entity\Group;
use App\Entity\GroupChannel;
use App\Entity\GroupInvitation;
use App\Entity\GroupMember;
use App\Entity\GroupMessage;
use App\Entity\Notification;
use App\Entity\Report;
use App\Entity\TodoAssignment;
use App\Entity\TodoNode;
use App\Entity\User;
use App\Moderation\ReportTargetType;

class GroupTest extends FunctionalTestCase
{
    public function testGroupsPageShowsInvitationsMyGroupsAndSuggestions(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $carol = $this->createMember('carol');
        $this->befriend($alice, $carol);
        $club = $this->createGroup('Club', $alice);
        $painters = $this->createGroup('Peintres', $carol);
        $private = $this->createGroup('Tournoi', $bob, public: false);
        $this->entityManager()->persist((new GroupInvitation())->setInvitedBy($bob)->setInvitedUser($alice)->setUsergroup($private));
        $this->entityManager()->flush();
        $this->client->loginUser($alice);
        $this->client->request('GET', '/groups/');
        self::assertSelectorTextContains('#invitations', 'Tournoi');
        self::assertSelectorTextContains('[aria-labelledby="my-groups-title"]', 'Club');
        self::assertSelectorExists('[aria-labelledby="my-groups-title"] .group-owner-crown');
        self::assertSelectorTextContains('[aria-labelledby="suggestions-title"]', 'Peintres');
        self::assertSelectorTextContains('[aria-labelledby="suggestions-title"]', '1 ami est dans ce groupe');
        $this->client->request('GET', '/group-invitation/list');
        self::assertResponseRedirects('/groups/#invitations', 301);
        self::assertNotNull($club->getId());
        self::assertNotNull($painters->getId());
    }

    public function testMemberArrangesTheOrderOfTheirGroups(): void
    {
        $alice = $this->createMember('alice');
        $first = $this->createGroup('Premier', $alice);
        $second = $this->createGroup('Second', $alice);
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/groups/');
        $token = $crawler->filter('form[action="/groups/tri"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/groups/ordre', ['_token' => $token, 'ids' => [$second->getId(), $first->getId()]]);
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/groups/');
        $names = $crawler->filter('[data-sortable-target="item"] .list-row-main a')->each(static fn ($link) => trim($link->text()));
        self::assertSame(['Second', 'Premier'], $names);
    }

    public function testInvitationRightsFollowTheGroupSetting(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $carol = $this->createMember('carol');
        $this->befriend($bob, $carol);
        $group = $this->createGroup('Club', $alice, joinable: false);
        $group->setInviteRole('admin');
        $this->addMember($group, $bob, 'member');
        $this->entityManager()->flush();
        $this->client->loginUser($bob);
        $this->client->request('GET', '/groups/' . $group->getSlug());
        self::assertSelectorNotExists('#group-invite');
        $group = $this->entityManager()->find(Group::class, $group->getId());
        $group->setIsJoinable(true);
        $this->entityManager()->flush();
        $crawler = $this->client->request('GET', '/groups/' . $group->getSlug());
        $form = $crawler->filter('#group-invite form')->form();
        $form['user_ids'][0]->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/groups/' . $group->getSlug());
        $invitation = $this->entityManager()->getRepository(GroupInvitation::class)->findOneBy(['usergroup' => $group->getId()]);
        self::assertSame('carol', $invitation->getInvitedUser()->getUsername());
        self::assertSame(1, $this->entityManager()->getRepository(Notification::class)->count(['type' => Notification::TYPE_GROUP_INVITATION]));
    }

    public function testAssignmentRequestWaitsForTheManagerApproval(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $group = $this->createGroup('Club', $alice);
        $group->setTodoViewRole('member');
        $this->addMember($group, $bob, 'member');
        $task = $this->createTask($group, $alice, 'Peindre les Intercessors');
        $this->client->loginUser($bob);
        $token = $this->todoToken($group);
        $this->client->request('POST', '/groups/' . $group->getSlug() . '/todo/task/' . $task->getId() . '/request', [], [], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        $assignment = $this->entityManager()->getRepository(TodoAssignment::class)->findOneBy([]);
        self::assertTrue($assignment->isPending());
        self::assertSame(1, $this->entityManager()->getRepository(Notification::class)->count(['type' => Notification::TYPE_TODO_ASSIGNMENT, 'recipient' => $alice->getId()]));
        $this->client->request('POST', '/groups/' . $group->getSlug() . '/todo/progress/up/' . $task->getId(), [], [], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->reload($alice));
        $this->client->request('POST', '/groups/' . $group->getSlug() . '/todo/assignment/' . $assignment->getId() . '/accept', [], [], ['HTTP_X_CSRF_TOKEN' => $this->todoToken($group)]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('category-assignees-', (string) json_decode((string) $this->client->getResponse()->getContent(), true)['category']);
        $this->entityManager()->clear();
        self::assertTrue($this->entityManager()->find(TodoAssignment::class, $assignment->getId())->isAccepted());
        $this->client->loginUser($this->entityManager()->find(User::class, $bob->getId()));
        $this->client->request('POST', '/groups/' . $group->getSlug() . '/todo/progress/up/' . $task->getId(), [], [], ['HTTP_X_CSRF_TOKEN' => $this->todoToken($group)]);
        self::assertResponseIsSuccessful();
    }

    public function testFreeAssignmentIsDirectLimitedAndOnlyManagersRemove(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $carol = $this->createMember('carol');
        $group = $this->createGroup('Club', $alice);
        $group->setTodoViewRole('member')->setAssignmentRole('member')->setMaxAssigneesPerTask(1);
        $this->addMember($group, $bob, 'member');
        $this->addMember($group, $carol, 'member');
        $task = $this->createTask($group, $alice, 'Soclage');
        $slug = $group->getSlug();
        $this->client->loginUser($bob);
        $this->client->request('POST', '/groups/' . $slug . '/todo/task/' . $task->getId() . '/request', [], [], ['HTTP_X_CSRF_TOKEN' => $this->todoToken($group)]);
        $assignment = $this->entityManager()->getRepository(TodoAssignment::class)->findOneBy([]);
        self::assertTrue($assignment->isAccepted());
        $this->client->request('POST', '/groups/' . $slug . '/todo/assignment/' . $assignment->getId() . '/remove', [], [], ['HTTP_X_CSRF_TOKEN' => $this->todoToken($group)]);
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->reload($carol));
        $this->client->request('POST', '/groups/' . $slug . '/todo/task/' . $task->getId() . '/request', [], [], ['HTTP_X_CSRF_TOKEN' => $this->todoToken($group)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->entityManager()->getRepository(TodoAssignment::class)->count([]));
        $this->client->loginUser($this->entityManager()->find(User::class, $alice->getId()));
        $this->client->request('POST', '/groups/' . $slug . '/todo/assignment/' . $assignment->getId() . '/remove', [], [], ['HTTP_X_CSRF_TOKEN' => $this->todoToken($group)]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->entityManager()->getRepository(TodoAssignment::class)->count([]));
    }

    public function testMentionsReachMutedMembersButMessagesDoNot(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $carol = $this->createMember('carol');
        $group = $this->createGroup('Club', $alice);
        $this->addMember($group, $bob, 'member')->setMuted(true);
        $this->addMember($group, $carol, 'member')->setMuted(true);
        $channel = $this->createChannel($group);
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/groups/' . $group->getSlug());
        $token = $crawler->filter('#message-form input[name="_token"]')->attr('value');
        $this->client->xmlHttpRequest('POST', '/groups/' . $group->getSlug() . '/message', ['_token' => $token, 'channel_id' => $channel->getId(), 'content' => 'Salut @bob !']);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('<a href="/profil/bob" class="mention">@bob</a>', $answer['contentHtml']);
        $notifications = $this->entityManager()->getRepository(Notification::class);
        self::assertSame(1, $notifications->count(['type' => Notification::TYPE_GROUP_MENTION, 'recipient' => $bob->getId()]));
        self::assertSame(0, $notifications->count(['type' => Notification::TYPE_GROUP_MESSAGE]));
    }

    public function testGroupAndGroupMessagesCanBeReported(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $group = $this->createGroup('Club', $alice);
        $this->addMember($group, $bob, 'member');
        $channel = $this->createChannel($group);
        $message = (new GroupMessage())->setContent('Message insultant')->setAuthor($alice)->setUsergroup($group)->setChannel($channel);
        $this->entityManager()->persist($message);
        $this->entityManager()->flush();
        $this->client->loginUser($bob);
        foreach ([['message-groupe', $message->getId()], ['groupe', $group->getId()]] as [$type, $id]) {
            $crawler = $this->client->request('GET', '/signaler/' . $type . '/' . $id);
            $this->client->submit($crawler->selectButton('Envoyer le signalement')->form(['report_form[reason]' => 'harassment']));
            self::assertResponseRedirects();
        }
        $reports = $this->entityManager()->getRepository(Report::class)->findBy([], ['id' => 'ASC']);
        self::assertSame(ReportTargetType::GroupMessage, $reports[0]->getTargetType());
        self::assertSame(ReportTargetType::Group, $reports[1]->getTargetType());
        self::assertSame('alice', $reports[1]->getTargetAuthor()->getUsername());
    }

    private function createGroup(string $name, User $owner, bool $public = true, bool $joinable = true): Group
    {
        $group = (new Group())->setName($name)->setSlug(strtolower($name) . '-' . uniqid())->setIsPublic($public)->setIsJoinable($joinable)->setCreator($owner);
        $this->entityManager()->persist($group);
        $this->addMember($group, $owner, 'owner');
        $this->entityManager()->flush();
        return $group;
    }

    private function addMember(Group $group, User $user, string $role): GroupMember
    {
        $member = (new GroupMember())->setUser($user)->setUsergroup($group)->setRole($role);
        $group->addMember($member);
        $this->entityManager()->persist($member);
        $this->entityManager()->flush();
        return $member;
    }

    private function createChannel(Group $group): GroupChannel
    {
        $channel = (new GroupChannel())->setName('général')->setCanRead('member')->setCanWrite('member')->setPosition(0);
        $group->addChannel($channel);
        $this->entityManager()->persist($channel);
        $this->entityManager()->flush();
        return $channel;
    }

    private function createTask(Group $group, User $owner, string $title): TodoNode
    {
        $list = (new TodoNode())->setTitle('Projet')->setType(TodoNode::TYPE_LIST)->setOwner($owner)->setUsergroup($group);
        $category = (new TodoNode())->setTitle('Infanterie')->setType(TodoNode::TYPE_CATEGORY)->setOwner($owner)->setUsergroup($group)->setParent($list);
        $task = (new TodoNode())->setTitle($title)->setType(TodoNode::TYPE_ITEM)->setOwner($owner)->setUsergroup($group)->setParent($category);
        foreach ([$list, $category, $task] as $node) {
            $this->entityManager()->persist($node);
        }
        $this->entityManager()->flush();
        return $task;
    }

    private function todoToken(Group $group): string
    {
        $this->client->request('GET', '/groups/' . $group->getSlug() . '/todo/');
        preg_match('/data-todo-token-value="([^"]+)"/', (string) $this->client->getResponse()->getContent(), $matches);
        return $matches[1];
    }

    private function befriend(User $requester, User $receiver): void
    {
        $this->entityManager()->persist((new Friendship())->setRequester($requester)->setReceiver($receiver)->setStatus('accepted'));
        $this->entityManager()->flush();
    }
}
