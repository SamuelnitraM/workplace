<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Http\SafeReferer;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Security\SubmissionThrottle;
use App\Security\ThrottledAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public const RESEND_CSRF_TOKEN_ID = 'verify_email_resend';

    public function __construct(private readonly EmailVerifier $emailVerifier)
    {
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, Security $security, EntityManagerInterface $entityManager, SubmissionThrottle $throttle): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $throttle->acceptsClientForm($form, ThrottledAction::Registration, $request)) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            $entityManager->persist($user);
            $entityManager->flush();
            if (!$this->emailVerifier->sendEmailConfirmation($user)) {
                $this->addFlash('warning', 'L\'e-mail de confirmation n\'a pas pu être envoyé. Tu pourras le renvoyer depuis le bandeau en haut de page.');
            }
            // Automatic login, then the optional guided tour (resumable from the home page)
            $security->login($user, 'form_login', 'main');
            return $this->redirectToRoute('app_onboarding');
        }
        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: EmailVerifier::VERIFY_ROUTE, methods: ['GET'])]
    public function verifyUserEmail(Request $request, UserRepository $userRepository, TranslatorInterface $translator): Response
    {
        $user = $userRepository->find($request->query->getInt('id'));
        if ($user === null) {
            $this->addFlash('error', 'Ce lien de confirmation est invalide.');
            return $this->redirectToRoute('app_home');
        }
        if ($user->isVerified()) {
            $this->addFlash('info', 'Ton adresse e-mail est déjà confirmée.');
            return $this->redirectToRoute('app_home');
        }
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));
            return $this->redirectToRoute('app_home');
        }
        $this->addFlash('success', 'Ton adresse e-mail est confirmée. Merci !');
        return $this->redirectToRoute('app_home');
    }

    #[Route('/verify/email/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid(self::RESEND_CSRF_TOKEN_ID)]
    public function resendVerificationEmail(Request $request, SubmissionThrottle $throttle): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->isVerified()) {
            $this->addFlash('info', 'Ton adresse e-mail est déjà confirmée.');
        } elseif (!$throttle->tryConsumeForUser(ThrottledAction::VerificationEmail, $user)) {
            $this->addFlash('error', ThrottledAction::VerificationEmail->refusalMessage());
        } elseif ($this->emailVerifier->sendEmailConfirmation($user)) {
            $this->addFlash('success', 'Un nouveau lien de confirmation a été envoyé à ' . $user->getEmail() . '.');
        } else {
            $this->addFlash('error', 'L\'e-mail n\'a pas pu être envoyé. Réessaie dans quelques minutes.');
        }
        return $this->redirect(SafeReferer::urlOr($request, $this->generateUrl('app_home')));
    }
}
