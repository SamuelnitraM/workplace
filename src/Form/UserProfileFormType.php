<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => "Nom d'utilisateur",
                'constraints' => [
                    new NotBlank(message: 'Veuillez entrer un nom d\'utilisateur'),
                    new Length(
                        min: 3,
                        max: 50,
                        maxMessage: 'Votre pseudo ne peut pas dépasser {{ limit }} caractères',
                    ),
                ],
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Biographie',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Parlez-nous de vous...',
                    'rows' => 4
                ],
            ])
            ->add('favoriteFaction', ChoiceType::class, [
                'label' => 'Faction favorite', 'required' => false,
                'placeholder' => '-- Choisir une faction --',
                'choices' => [
                    'Space marines' => ['Space Marines' => 'Space Marines', 'Blood Angels' => 'Blood Angels', 'Dark Angels' => 'Dark Angels', 'Space Wolves' => 'Space Wolves', 'Grey Knights' => 'Grey Knights', 'Deathwatch' => 'Deathwatch'],
                    'Imperium' => ['Adeptus Custodes' => 'Adeptus Custodes', 'Sisters of Battle' => 'Sisters of Battle', 'Astra Militarum' => 'Astra Militarum', 'Adeptus Mechanicus' => 'Adeptus Mechanicus', 'Imperial Knights' => 'Imperial Knights'],
                    'Chaos' => ['Chaos Space Marines' => 'Chaos Space Marines', 'Death Guard' => 'Death Guard', 'Thousand Sons' => 'Thousand Sons', 'World Eaters' => 'World Eaters', "Emperor's Children" => "Emperor's Children", 'Chaos Knights' => 'Chaos Knights', 'Daemons' => 'Daemons'],
                    'Xenos' => ['Orks' => 'Orks', 'Eldar' => 'Eldar', 'Drukhari' => 'Drukhari', 'Tyranids' => 'Tyranids', 'Genestealer Cults' => 'Genestealer Cults', 'Tau' => 'Tau', 'Necrons' => 'Necrons', 'Leagues of Votann' => 'Leagues of Votann'],
                ],
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
            ->add('avatarFile', FileType::class, [
                'label' => 'Photo de profil',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'mimeTypesMessage' => 'Formats acceptés : JPG, PNG, WEBP',
                    ]),
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