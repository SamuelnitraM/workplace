<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;


class RegistrationFormType extends AbstractType
{
    public const USERNAME_MIN_LENGTH = 3;
    public const USERNAME_MAX_LENGTH = 50;
    /** Équivalent HTML (attribut pattern, compatible drapeau « v ») de la contrainte Regex du pseudo. */
    public const USERNAME_HTML_PATTERN = '[A-Za-z0-9_.\-]+';
    public const EMAIL_MAX_LENGTH = 180;
    public const PASSWORD_MIN_LENGTH = 12;
    public const PASSWORD_MAX_LENGTH = 4096;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Les attributs HTML natifs reprennent les contraintes pour guider la saisie ;
        // la validation serveur reste la référence.
        $builder
            ->add('username', TextType::class, [
                'attr' => [
                    'minlength' => self::USERNAME_MIN_LENGTH,
                    'maxlength' => self::USERNAME_MAX_LENGTH,
                    'pattern' => self::USERNAME_HTML_PATTERN,
                    'autocomplete' => 'username',
                    'autocapitalize' => 'none',
                    'spellcheck' => 'false',
                ],
                'constraints' => [
                    new NotBlank(message: 'Veuillez choisir un nom d\'utilisateur'),
                    new Length(
                        min: self::USERNAME_MIN_LENGTH,
                        minMessage: 'Votre pseudo doit contenir au moins {{ limit }} caractères',
                        max: self::USERNAME_MAX_LENGTH,
                        maxMessage: 'Votre pseudo ne peut pas dépasser {{ limit }} caractères',
                    ),
                    new Regex(
                        pattern: '/^[A-Za-z0-9_.-]+$/D',
                        message: 'Votre pseudo ne peut contenir que des lettres sans accent, des chiffres, et les caractères « _ », « . » et « - ».',
                    ),
                ],
            ])

            ->add('email', EmailType::class, [
                'attr' => [
                    'maxlength' => self::EMAIL_MAX_LENGTH,
                    'autocomplete' => 'email',
                ],
                'constraints' => [
                    new NotBlank(message: 'Veuillez entrer une adresse email'),
                    new Email(message: 'Veuillez entrer une adresse email valide.'),
                    new Length(max: self::EMAIL_MAX_LENGTH, maxMessage: 'Votre adresse email ne peut pas dépasser {{ limit }} caractères'),
                ],
            ])

            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue(message: 'Vous devez accepter les conditions d\'utilisation.'),
                ],
            ])

            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'attr' => [
                    'minlength' => self::PASSWORD_MIN_LENGTH,
                    'maxlength' => self::PASSWORD_MAX_LENGTH,
                    'autocomplete' => 'new-password',
                ],
                'constraints' => [
                    new NotBlank(message: 'Veuillez entrer un mot de passe'),
                    new Length(
                        min: self::PASSWORD_MIN_LENGTH,
                        minMessage: 'Votre mot de passe doit contenir au moins {{ limit }} caractères',
                        max: self::PASSWORD_MAX_LENGTH,
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
