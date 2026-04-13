<?php

namespace App\Form;

use App\Entity\Reservations;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;

class ReservationsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $maxPlaces = $options['max_places'];

        $builder
            ->add('nomComplet', TextType::class, [
                'label'       => 'Nom complet',
                'attr'        => ['placeholder' => 'Votre nom et prénom'],
                'constraints' => [new NotBlank(['message' => 'Le nom est obligatoire.'])],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'attr'        => ['placeholder' => 'votre@email.com'],
                'constraints' => [new NotBlank(['message' => "L'email est obligatoire."])],
            ])
            ->add('telephone', TelType::class, [
                'label'    => 'Téléphone',
                'required' => false,
                'attr'     => ['placeholder' => '+216 XX XXX XXX'],
            ])
            ->add('nombreAdultes', IntegerType::class, [
                'label'       => "Nombre d'adultes",
                'attr'        => ['min' => 0, 'max' => $maxPlaces],
                'data'        => 1,
                'constraints' => [
                    new PositiveOrZero(),
                    new Range(['min' => 0, 'max' => $maxPlaces,
                        'maxMessage' => 'Maximum {{ limit }} places disponibles.']),
                ],
            ])
            ->add('nombreEnfants', IntegerType::class, [
                'label'       => "Nombre d'enfants",
                'attr'        => ['min' => 0, 'max' => $maxPlaces],
                'data'        => 0,
                'constraints' => [
                    new PositiveOrZero(),
                    new Range(['min' => 0, 'max' => $maxPlaces,
                        'maxMessage' => 'Maximum {{ limit }} places disponibles.']),
                ],
            ])
            ->add('demandesSpeciales', TextareaType::class, [
                'label'    => 'Demandes spéciales (optionnel)',
                'required' => false,
                'attr'     => [
                    'placeholder' => 'Allergies, besoins spécifiques, accessibilité...',
                    'rows'        => 3,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservations::class,
            'max_places' => 100,
        ]);
    }
}