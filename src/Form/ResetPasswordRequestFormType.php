<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Password reset request: the account is found by e-mail or username, like at login. */
class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('identifier', TextType::class, [
            'label' => 'E-mail ou pseudo',
            'attr' => [
                'autocomplete' => 'username',
                'autocapitalize' => 'none',
                'spellcheck' => 'false',
                'maxlength' => RegistrationFormType::EMAIL_MAX_LENGTH,
                'placeholder' => 'Pseudo ou adresse e-mail',
            ],
            'constraints' => [
                new NotBlank(message: 'Indique ton adresse e-mail ou ton pseudo.'),
                new Length(max: RegistrationFormType::EMAIL_MAX_LENGTH),
            ],
        ]);
    }
}
