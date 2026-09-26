<?php

namespace App\Controller;

use App\Entity\GroupMember;
use App\Entity\User;
use App\Form\OnboardingProfileFormType;
use App\Form\UserProfileFormType;
use App\Repository\GroupRepository;
use App\Security\Voter\GroupVoter;
use App\Profile\ProfileImage;
use App\Service\ProfileImageUploader;
use App\Service\GalleryPhotoUploader;
use App\Service\GamificationService;
use App\Service\OnboardingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Présentation guidée après l'inscription (/bienvenue) : 4 étapes facultatives, sans JavaScript
 * (formulaires POST + CSRF, puis redirection : Post/Redirect/Get). Jamais imposée : l'accueil affiche
 * seulement une bannière tant que User::onboardingCompletedAt est NULL.
 */
#[Route('/bienvenue', name: 'app_onboarding')]
#[IsGranted('ROLE_USER')]
final class OnboardingController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'onboarding';
    /** Groupes rejoints pendant la présentation : restent affichés « ✓ Rejoint » à l'étape 3. */
    private const SESSION_JOINED_GROUPS = 'onboarding_joined_groups';

    public function __construct(
        private OnboardingService $onboarding,
        private EntityManagerInterface $em,
    ) {}

    /** Reprend la présentation à l'étape mémorisée. */
    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToStep($this->currentUser()->getOnboardingStep());
    }

    #[Route('/etape/{step}', name: '_step', requirements: ['step' => '[1-4]'], methods: ['GET', 'POST'])]
    public function step(int $step, Request $request, ProfileImageUploader $profileImageUploader, GalleryPhotoUploader $galleryUploader): Response
    {
        $user = $this->currentUser();

        return match ($step) {
            1 => $this->factionStep($request, $user),
            2 => $this->profileStep($request, $user, $profileImageUploader, $galleryUploader),
            3 => $this->groupsStep($request, $user),
            default => $this->render('onboarding/step4.html.twig', $this->common($user, 4) + [
                'dailyLoginXp' => GamificationService::DAILY_LOGIN_XP,
                'streakBonusXp' => GamificationService::STREAK_BONUS_XP,
                'streakBonusEvery' => GamificationService::STREAK_BONUS_EVERY,
                'streakMaxMissedDays' => GamificationService::STREAK_MAX_MISSED_DAYS,
            ]),
        };
    }

    /** « Passer cette étape » (ou « Continuer » à l'étape 3) : étape suivante, sans rien enregistrer. */
    #[Route('/etape/{step}/suivante', name: '_next', requirements: ['step' => '[1-3]'], methods: ['POST'])]
    public function next(int $step, Request $request): Response
    {
        $this->denyUnlessCsrfValid($request);
        $user = $this->currentUser();
        $this->onboarding->advanceTo($user, $step + 1);
        $this->em->flush();

        return $this->redirectToStep($step + 1);
    }

    /** Étape 3 : rejoindre un groupe proposé (mêmes règles que GroupController::join, via GroupVoter). */
    #[Route('/groupes/{id}/rejoindre', name: '_join', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function join(int $id, Request $request, GroupRepository $groupRepository): Response
    {
        $this->denyUnlessCsrfValid($request);
        $group = $groupRepository->find($id) ?? throw $this->createNotFoundException('Groupe introuvable.');

        if ($this->isGranted(GroupVoter::MEMBER, $group)) {
            $this->addFlash('error', 'Tu es déjà membre de ce groupe.');
        } elseif (!$this->isGranted(GroupVoter::JOIN, $group)) {
            $this->addFlash('error', 'Ce groupe n\'accepte pas de nouvelles demandes.');
        } else {
            $member = (new GroupMember())->setUser($this->currentUser())->setUsergroup($group)->setRole('member');
            $this->em->persist($member);
            $this->em->flush();

            $session = $request->getSession();
            $joined = (array) $session->get(self::SESSION_JOINED_GROUPS, []);
            $session->set(self::SESSION_JOINED_GROUPS, array_values(array_unique([...$joined, $group->getId()])));
            $this->addFlash('success', sprintf('Tu as rejoint le groupe « %s » !', $group->getName()));
        }

        return $this->redirectToRoute('app_onboarding_step', ['step' => 3, '_fragment' => 'groupe-' . $id]);
    }

    /** Étape 4 : « Terminer ». */
    #[Route('/terminer', name: '_finish', methods: ['POST'])]
    public function finish(Request $request): Response
    {
        $this->denyUnlessCsrfValid($request);
        $this->onboarding->complete($this->currentUser());
        $this->em->flush();
        $request->getSession()->remove(self::SESSION_JOINED_GROUPS);

        $this->addFlash('success', 'Présentation terminée : bienvenue dans la communauté, à toi de jouer !');

        return $this->redirectToRoute('app_home');
    }

    private function factionStep(Request $request, User $user): Response
    {
        $choices = UserProfileFormType::factionChoices();

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request);
            $faction = $request->request->getString('faction');
            if (!in_array($faction, OnboardingService::factionValues(), true)) {
                $this->addFlash('error', 'Choisis une faction dans la liste, ou passe cette étape.');

                return $this->redirectToStep(1);
            }
            $user->setFavoriteFaction($faction);
            $this->onboarding->advanceTo($user, 2);
            $this->em->flush();

            return $this->redirectToStep(2);
        }

        return $this->render('onboarding/step1.html.twig', $this->common($user, 1) + [
            'factionChoices' => $choices,
        ]);
    }

    private function profileStep(Request $request, User $user, ProfileImageUploader $profileImageUploader, GalleryPhotoUploader $galleryUploader): Response
    {
        $form = $this->createForm(OnboardingProfileFormType::class, ['bio' => $user->getBio()], [
            'action' => $this->generateUrl('app_onboarding_step', ['step' => 2]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = [];
            $bio = trim((string) $form->get('bio')->getData());
            $user->setBio($bio !== '' ? $bio : null);

            $oldAvatar = $user->getAvatar();
            $avatarFile = $form->get('avatarFile')->getData();
            $cropFrame = $request->request->get(ProfileImage::Avatar->cropFieldName());
            if ($avatarFile instanceof UploadedFile && ($error = $profileImageUploader->upload($user, ProfileImage::Avatar, $avatarFile, is_string($cropFrame) ? $cropFrame : null))) {
                $errors[] = $error;
            }

            $photoFile = $form->get('photoFile')->getData();
            if ($photoFile instanceof UploadedFile) {
                $result = $galleryUploader->upload($user, $photoFile, (string) $form->get('photoDescription')->getData());
                if (is_string($result)) {
                    $errors[] = $result;
                }
            }

            if (!$errors) {
                $this->onboarding->advanceTo($user, 3);
            }
            $this->em->flush();
            $profileImageUploader->deleteReplaced($user, ProfileImage::Avatar, $oldAvatar);

            // Ce qui a réussi est enregistré ; en cas d'erreur, on reste sur l'étape pour corriger
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToStep($errors ? 2 : 3);
        }

        return $this->render('onboarding/step2.html.twig', $this->common($user, 2) + [
            'form' => $form,
            'galleryFull' => $galleryUploader->isFull($user),
        ]);
    }

    private function groupsStep(Request $request, User $user): Response
    {
        $joinedIds = array_map('intval', (array) $request->getSession()->get(self::SESSION_JOINED_GROUPS, []));

        return $this->render('onboarding/step3.html.twig', $this->common($user, 3) + [
            'suggestions' => $this->onboarding->suggestGroups($user, $joinedIds),
        ]);
    }

    /** Variables communes aux étapes (indicateur de progression). */
    private function common(User $user, int $step): array
    {
        return [
            'step' => $step,
            'stepCount' => OnboardingService::STEP_COUNT,
            'steps' => OnboardingService::STEPS,
            'user' => $user,
        ];
    }

    private function redirectToStep(int $step): Response
    {
        return $this->redirectToRoute('app_onboarding_step', ['step' => max(1, min(OnboardingService::STEP_COUNT, $step))]);
    }

    private function denyUnlessCsrfValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
