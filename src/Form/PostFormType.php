<?php

namespace App\Form;

use App\Entity\Post;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class PostFormType extends AbstractType
{
    /** Maximum length of a forum message (reply, first post of a thread, editor preview), within the size of the TEXT column. */
    public const CONTENT_MAX_LENGTH = 20000;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextareaType::class, [
                'label' => false,
                'attr' => [
                    'placeholder' => 'Rédige ta réponse...',
                    'rows' => 5
                ],
                'constraints' => [
                    new NotBlank(message: 'La réponse ne peut pas être vide'),
                    new Length(min: 2, max: self::CONTENT_MAX_LENGTH),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Post::class,
        ]);
    }
}