<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The reset-by-e-mail flow proves the identity through the link: no current password there
        if ($options['require_current_password']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(message: 'Veuillez entrer votre mot de passe actuel'),
                ],
            ]);
        }
        $builder
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'minlength' => RegistrationFormType::PASSWORD_MIN_LENGTH,
                        'maxlength' => RegistrationFormType::PASSWORD_MAX_LENGTH,
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmer le nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'constraints' => [
                    new NotBlank(message: 'Veuillez entrer un nouveau mot de passe'),
                    new Length(
                        // Même règle qu'à l'inscription : une seule constante partagée
                        min: RegistrationFormType::PASSWORD_MIN_LENGTH,
                        minMessage: 'Votre mot de passe doit contenir au moins {{ limit }} caractères',
                        max: RegistrationFormType::PASSWORD_MAX_LENGTH,
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['require_current_password' => true]);
        $resolver->setAllowedTypes('require_current_password', 'bool');
    }
}