<?php

namespace App\Form;

use App\Entity\Badge;
use App\Entity\User;
use App\Gamification\UserTitleManager;
use App\Notification\NotificationSound;
use App\Profile\ProfileImage;
use App\Service\ProfileImageUploader;
use App\Service\BsDataFetcher;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

class UserProfileFormType extends AbstractType
{
    /**
     * Favorite faction choices: the playable factions of the army builder, grouped like its menu.
     *
     * @return array<string, array<string, string>>
     */
    public static function factionChoices(): array
    {
        $choices = [];
        foreach (BsDataFetcher::FACTION_GROUPS as $group => $factions) {
            $choices[$group] = array_combine($factions, $factions);
        }
        return $choices;
    }

    public const BIO_MAX_LENGTH = 255;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => "Nom d'utilisateur",
                'constraints' => RegistrationFormType::usernameConstraints('Entre un nom d\'utilisateur'),
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Biographie',
                'required' => false,
                'constraints' => [
                    new Length(max: self::BIO_MAX_LENGTH, maxMessage: 'Ta biographie ne peut pas dépasser {{ limit }} caractères'),
                ],
                'attr' => [
                    'maxlength' => self::BIO_MAX_LENGTH,
                    'placeholder' => 'Parle-nous de toi...',
                    'rows' => 4
                ],
            ])
            ->add('favoriteFaction', ChoiceType::class, [
                'label' => 'Faction favorite', 'required' => false,
                'placeholder' => '-- Choisir une faction --',
                'choices' => self::factionChoices(),
            ])
            ->add('showActivity', ChoiceType::class, [
                'label' => 'Afficher mon activité récente sur mon profil',
                'choices' => [
                    'Oui' => true,
                    'Non' => false,
                ],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('notificationSound', ChoiceType::class, [
                'label' => 'Son des notifications',
                'choices' => NotificationSound::choices(),
                'expanded' => true,
                'multiple' => false,
            ])
            // Title: unlocked badges only (any other submitted identifier is refused by the form)
            ->add('titleBadge', EntityType::class, [
                'label' => 'Titre affiché à côté de ton pseudo',
                'class' => Badge::class,
                'required' => false,
                'placeholder' => '— Aucun titre —',
                'query_builder' => static fn (EntityRepository $repository) => UserTitleManager::unlockedBadgesQueryBuilder(
                    $repository,
                    $options['data'] instanceof User ? $options['data'] : null,
                ),
                'choice_label' => static fn (Badge $badge) => sprintf('%s (%s)', $badge->getName(), $badge->getTierLabel()),
                'invalid_message' => 'Ce badge n’est pas débloqué : il ne peut pas servir de titre.',
            ])
            ->add('avatarFile', FileType::class, [
                'label' => 'Photo de profil',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    ProfileImageUploader::fileConstraint(ProfileImage::Avatar),
                ],
            ])
            ->add('coverFile', FileType::class, [
                'label' => 'Bannière',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    ProfileImageUploader::fileConstraint(ProfileImage::Cover),
                ],
                'attr' => ['accept' => implode(',', ProfileImageUploader::MIME_TYPES)],
                'help' => 'Image affichée en haut de ton profil. JPG, PNG ou WEBP, ' . ProfileImage::Cover->maxFileSize() . 'o maximum ; format large conseillé (ex. 1600 × 400).',
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