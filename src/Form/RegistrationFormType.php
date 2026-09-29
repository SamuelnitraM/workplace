<?php

namespace App\Form;

use App\Entity\User;
use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public const USERNAME_MIN_LENGTH = 3;
    public const USERNAME_MAX_LENGTH = 50;
    /** HTML equivalent (pattern attribute, compatible with the « v » flag) of the username Regex constraint. */
    public const USERNAME_HTML_PATTERN = '[A-Za-z0-9_.\-]+';
    public const EMAIL_MAX_LENGTH = 180;

    /**
     * Username rules of every form (registration, profile edit).
     *
     * @return list<Constraint>
     */
    public static function usernameConstraints(string $blankMessage): array
    {
        return [
            new NotBlank(message: $blankMessage),
            new Length(
                min: self::USERNAME_MIN_LENGTH,
                minMessage: 'Ton pseudo doit contenir au moins {{ limit }} caractères',
                max: self::USERNAME_MAX_LENGTH,
                maxMessage: 'Ton pseudo ne peut pas dépasser {{ limit }} caractères',
            ),
            new Regex(
                pattern: '/^[A-Za-z0-9_.-]+$/D',
                message: 'Ton pseudo ne peut contenir que des lettres sans accent, des chiffres, et les caractères « _ », « . » et « - ».',
            ),
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Native HTML attributes mirror the constraints to guide the input; the server validation remains the reference.
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
                'constraints' => self::usernameConstraints('Choisis un nom d\'utilisateur'),
            ])

            ->add('email', EmailType::class, [
                'attr' => [
                    'maxlength' => self::EMAIL_MAX_LENGTH,
                    'autocomplete' => 'email',
                ],
                'constraints' => [
                    new NotBlank(message: 'Entre une adresse email'),
                    new Email(message: 'Entre une adresse email valide.'),
                    new Length(max: self::EMAIL_MAX_LENGTH, maxMessage: 'Ton adresse email ne peut pas dépasser {{ limit }} caractères'),
                ],
            ])

            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue(message: 'Tu dois avoir l\'âge minimum et accepter les conditions d\'utilisation.'),
                ],
            ])

            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'attr' => [
                    'minlength' => PasswordPolicy::MIN_LENGTH,
                    'maxlength' => PasswordPolicy::MAX_LENGTH,
                    'autocomplete' => 'new-password',
                ],
                'constraints' => PasswordPolicy::constraints('Entre un mot de passe'),
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
