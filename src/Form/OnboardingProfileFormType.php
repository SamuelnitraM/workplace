<?php

namespace App\Form;

use App\Entity\GalleryPhoto;
use App\Service\AvatarUploader;
use App\Service\GalleryPhotoUploader;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Présentation guidée, étape 2 « Ton profil » : photo de profil + bio (mêmes règles que UserProfileFormType)
 * et, en option, une première photo de galerie (mêmes limites que la galerie du profil).
 * Données : ['bio' => ?string] ; les fichiers et la description de photo ne sont pas liés.
 */
class OnboardingProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('avatarFile', FileType::class, [
                'label' => 'Photo de profil',
                'required' => false,
                'constraints' => [AvatarUploader::fileConstraint()],
                'attr' => ['accept' => implode(',', AvatarUploader::MIME_TYPES)],
                'help' => 'JPG, PNG ou WEBP, ' . AvatarUploader::MAX_SIZE . 'o maximum.',
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Ta bio',
                'required' => false,
                'constraints' => [
                    new Length(max: UserProfileFormType::BIO_MAX_LENGTH, maxMessage: 'Votre biographie ne peut pas dépasser {{ limit }} caractères'),
                ],
                'attr' => [
                    'maxlength' => UserProfileFormType::BIO_MAX_LENGTH,
                    'rows' => 3,
                    'placeholder' => 'Ex. : Peintre du dimanche, fan des Ultramarines depuis 2008…',
                ],
                'help' => UserProfileFormType::BIO_MAX_LENGTH . ' caractères maximum.',
            ])
            ->add('photoFile', FileType::class, [
                'label' => 'Montre ta dernière figurine (facultatif)',
                'required' => false,
                'constraints' => [
                    new File(
                        maxSize: GalleryPhotoUploader::MAX_BYTES,
                        mimeTypes: GalleryPhotoUploader::MIME_TYPES,
                        mimeTypesMessage: 'Photo invalide : JPG, PNG ou WEBP de 10 Mo maximum.',
                    ),
                ],
                'attr' => ['accept' => implode(',', GalleryPhotoUploader::MIME_TYPES)],
                'help' => 'Elle rejoindra ta galerie. JPG, PNG ou WEBP, 10 Mo maximum.',
            ])
            ->add('photoDescription', TextareaType::class, [
                'label' => 'Description de la photo',
                'required' => false,
                'constraints' => [
                    new Length(max: GalleryPhoto::DESCRIPTION_MAX_LENGTH, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.'),
                ],
                'attr' => [
                    'maxlength' => GalleryPhoto::DESCRIPTION_MAX_LENGTH,
                    'rows' => 2,
                    'placeholder' => 'Ex. : Capitaine en armure Gravis, schéma maison',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'onboarding_profile',
        ]);
    }
}
