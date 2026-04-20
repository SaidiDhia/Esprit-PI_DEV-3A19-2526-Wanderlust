<?php

namespace App\Form;

use App\Entity\DynamicPricingRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

class DynamicPricingRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('eventType', ChoiceType::class, [
                'label' => 'Type d\'événement',
                'choices' => [
                    'Concert' => 'concert',
                    'Festival' => 'festival',
                    'Théâtre' => 'theatre',
                    'Sport' => 'sport',
                    'Conférence' => 'conference',
                    'Atelier' => 'atelier',
                    'Par défaut' => 'default'
                ],
                'attr' => ['class' => 'form-select']
            ])
            ->add('emotionalFloorPercentage', NumberType::class, [
                'label' => 'Prix plancher émotionnel (%)',
                'help' => 'Pourcentage minimum du prix de base (ex: 0.50 = 50%)',
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0.1,
                    'max' => 0.9,
                    'step' => 0.01
                ]
            ])
            ->add('maxDiscountPercentage', NumberType::class, [
                'label' => 'Réduction maximale (%)',
                'help' => 'Pourcentage maximum de réduction autorisé (ex: 0.40 = 40%)',
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0.05,
                    'max' => 0.6,
                    'step' => 0.01
                ]
            ])
            ->add('timeWeight', NumberType::class, [
                'label' => 'Poids facteur temps',
                'help' => 'Importance du temps restant (0-1)',
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.01
                ]
            ])
            ->add('occupancyWeight', NumberType::class, [
                'label' => 'Poids remplissage',
                'help' => 'Importance du taux de remplissage (0-1)',
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.01
                ]
            ])
            ->add('popularityWeight', NumberType::class, [
                'label' => 'Poids popularité',
                'help' => 'Importance de la popularité (0-1)',
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.01
                ]
            ])
            ->add('occupancyThreshold', IntegerType::class, [
                'label' => 'Seuil de remplissage (%)',
                'help' => 'Seuil déclenchant la réduction (ex: 70 = 70%)',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 10,
                    'max' => 90
                ]
            ])
            ->add('reversibilityFactor', IntegerType::class, [
                'label' => 'Facteur de réversibilité (jours)',
                'help' => 'Nombre de jours pour permettre une remontée de prix',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'max' => 10
                ]
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Règle active',
                'required' => false,
                'attr' => ['class' => 'form-check-input']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DynamicPricingRule::class,
        ]);
    }
}
