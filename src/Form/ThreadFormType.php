<?php

namespace App\Form;

use App\Entity\Thread;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ThreadFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre du sujet',
                'attr' => ['placeholder' => 'Donne un titre clair à ton sujet'],
                'constraints' => [
                    new NotBlank(message: 'Le titre est obligatoire'),
                    new Length(min: 5, max: 255),
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Contenu',
                'mapped' => false,
                'attr' => [
                    'placeholder' => 'Rédige le contenu de ton sujet...',
                    'rows' => 8
                ],
                'constraints' => [
                    new NotBlank(message: 'Le contenu est obligatoire'),
                    new Length(min: 10, max: PostFormType::CONTENT_MAX_LENGTH),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Thread::class,
        ]);
    }
}