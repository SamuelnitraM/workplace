<?php

namespace App\Form;

use App\Entity\Badge;
use App\Entity\User;
use App\Gamification\UserTitleManager;
use App\Notification\NotificationSound;
use App\Service\AvatarUploader;
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
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class UserProfileFormType extends AbstractType
{
    /** Factions proposées (profil, présentation guidée), regroupées par famille. */
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
                'constraints' => [
                    new NotBlank(message: 'Veuillez entrer un nom d\'utilisateur'),
                    new Length(
                        min: 3,
                        minMessage: 'Votre pseudo doit contenir au moins {{ limit }} caractères',
                        max: 50,
                        maxMessage: 'Votre pseudo ne peut pas dépasser {{ limit }} caractères',
                    ),
                    new Regex(
                        pattern: '/^[A-Za-z0-9_.-]+$/D',
                        message: 'Votre pseudo ne peut contenir que des lettres sans accent, des chiffres, et les caractères « _ », « . » et « - ».',
                    ),
                ],
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Biographie',
                'required' => false,
                'constraints' => [
                    new Length(max: self::BIO_MAX_LENGTH, maxMessage: 'Votre biographie ne peut pas dépasser {{ limit }} caractères'),
                ],
                'attr' => [
                    'maxlength' => self::BIO_MAX_LENGTH,
                    'placeholder' => 'Parlez-nous de vous...',
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
            // Titre : uniquement les badges débloqués (un autre identifiant soumis est refusé par le formulaire)
            ->add('titleBadge', EntityType::class, [
                'label' => 'Titre affiché à côté de votre pseudo',
                'class' => Badge::class,
                'required' => false,
                'placeholder' => '— Aucun titre —',
                'query_builder' => static fn (EntityRepository $repository) => UserTitleManager::unlockedBadgesQueryBuilder(
                    $repository,
                    $options['data'] instanceof User ? $options['data'] : null,
                ),
                'choice_label' => static fn (Badge $badge) => sprintf('%s %s (%s)', $badge->getIcon(), $badge->getName(), $badge->getTierLabel()),
                'invalid_message' => 'Ce badge n’est pas débloqué : il ne peut pas servir de titre.',
            ])
            ->add('avatarFile', FileType::class, [
                'label' => 'Photo de profil',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    AvatarUploader::fileConstraint(),
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