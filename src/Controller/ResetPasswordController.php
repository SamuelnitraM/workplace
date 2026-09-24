<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Mailer\TransactionalMailer;
use App\Repository\UserRepository;
use App\Security\SubmissionThrottle;
use App\Security\ThrottledAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Forgotten password: request by e-mail, single-use link with a limited lifetime, new password.
 *
 * The response never reveals whether an account exists for the submitted identifier.
 */
#[Route('/mot-de-passe-oublie', name: 'app_')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, UserRepository $userRepository, TransactionalMailer $mailer, SubmissionThrottle $throttle): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_profil_change_password');
        }
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$throttle->tryConsumeForClient(ThrottledAction::PasswordResetRequest, $request)) {
                $this->addFlash('error', ThrottledAction::PasswordResetRequest->refusalMessage());
                return $this->redirectToRoute('app_forgot_password_request');
            }
            $user = $userRepository->findOneByEmailOrUsername(trim((string) $form->get('identifier')->getData()));
            if ($user !== null) {
                $this->sendResetEmail($user, $mailer);
            }
            // Same page whether the account exists or not
            $this->setTokenObjectInSession($this->resetPasswordHelper->generateFakeResetToken());
            return $this->redirectToRoute('app_check_email');
        }
        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    #[Route('/verifier', name: 'check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        $resetToken = $this->getTokenObjectFromSession() ?? $this->resetPasswordHelper->generateFakeResetToken();
        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    #[Route('/reinitialiser/{token}', name: 'reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, TranslatorInterface $translator, ?string $token = null): Response
    {
        // The token leaves the URL (history, referrer) and is kept in session for the form submission
        if ($token !== null) {
            $this->storeTokenInSession($token);
            return $this->redirectToRoute('app_reset_password');
        }
        $token = $this->getTokenFromSession();
        if ($token === null) {
            $this->addFlash('error', 'Ce lien de réinitialisation est invalide. Fais une nouvelle demande.');
            return $this->redirectToRoute('app_forgot_password_request');
        }
        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $exception) {
            $this->cleanSessionAfterReset();
            $this->addFlash('error', $translator->trans($exception->getReason(), [], 'ResetPasswordBundle'));
            return $this->redirectToRoute('app_forgot_password_request');
        }
        $form = $this->createForm(ChangePasswordFormType::class, null, ['require_current_password' => false]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Single use: the request is consumed before the password changes
            $this->resetPasswordHelper->removeResetRequest($token);
            /** @var string $plainPassword */
            $plainPassword = $form->get('newPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            // Following the link proves ownership of the address
            $user->setIsVerified(true);
            $this->entityManager->flush();
            $this->cleanSessionAfterReset();
            $this->addFlash('success', 'Ton mot de passe a été modifié. Tu peux maintenant te connecter.');
            return $this->redirectToRoute('app_login');
        }
        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form,
        ]);
    }

    private function sendResetEmail(User $user, TransactionalMailer $mailer): void
    {
        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // A request is already pending for this account (throttle_limit): the previous link stays valid
            return;
        }
        if (!$mailer->send($user, 'Réinitialisation de ton mot de passe', 'email/reset_password.html.twig', ['resetToken' => $resetToken])) {
            $this->resetPasswordHelper->removeResetRequest($resetToken->getToken());
        }
    }
}
