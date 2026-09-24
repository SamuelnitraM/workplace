<?php

namespace App\Form;

use App\Entity\Report;
use App\Moderation\ReportReason;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

class ReportFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', EnumType::class, [
                'class' => ReportReason::class,
                'label' => 'Motif',
                'expanded' => true,
                'choice_label' => static fn (ReportReason $reason): string => $reason->label(),
                'constraints' => [new NotNull(message: 'Choisis un motif.')],
            ])
            ->add('details', TextareaType::class, [
                'label' => 'Précisions',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'maxlength' => Report::DETAILS_MAX_LENGTH,
                    'placeholder' => 'Ce qui pose problème, le contexte…',
                ],
                'constraints' => [new Length(max: Report::DETAILS_MAX_LENGTH)],
            ]);
    }
}
