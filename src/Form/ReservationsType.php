<?php

namespace App\Form;

use App\Entity\Reservations;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Email;

class ReservationsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $maxPlaces = $options['max_places'] ?? 100;

        $builder
            // Informations personnelles
            ->add('nomComplet', TextType::class, [
                'label' => 'Nom complet',
                'attr' => [
                    'placeholder' => 'Entrez votre nom complet',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Le nom complet est obligatoire.'])
                ]
            ])

            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => [
                    'placeholder' => 'votre@email.com',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new NotBlank(['message' => "L'email est obligatoire."]),
                    new Email(['message' => "Veuillez entrer une adresse email valide."])
                ]
            ])

            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => [
                    'placeholder' => '+216 XX XXX XXX',
                    'class' => 'form-control'
                ],
                'help' => 'Optionnel mais recommandé'
            ])

            // Participants
            ->add('nombreAdultes', IntegerType::class, [
                'label' => "Nombre d'adultes",
                'attr' => [
                    'min' => 0,
                    'max' => $maxPlaces,
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new PositiveOrZero(['message' => 'Le nombre d\'adultes ne peut pas être négatif.']),
                    new Range([
                        'min' => 0,
                        'max' => $maxPlaces,
                        'notInRangeMessage' => 'Le nombre d\'adultes doit être entre {{ min }} et {{ max }}.'
                    ])
                ],
                'help' => 'Personnes de 18 ans et plus'
            ])

            ->add('nombreEnfants', IntegerType::class, [
                'label' => "Nombre d'enfants",
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'max' => $maxPlaces,
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new PositiveOrZero(['message' => 'Le nombre d\'enfants ne peut pas être négatif.']),
                    new Range([
                        'min' => 0,
                        'max' => $maxPlaces,
                        'notInRangeMessage' => 'Le nombre d\'enfants doit être entre {{ min }} et {{ max }}.'
                    ])
                ],
                'help' => 'Personnes de moins de 18 ans'
            ])

            // Demandes spéciales
            ->add('demandesSpeciales', TextareaType::class, [
                'label' => 'Demandes spéciales',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Allergies, besoins spécifiques, accessibilité...',
                    'rows' => 4,
                    'class' => 'form-control'
                ],
                'help' => 'Optionnel - Indiquez vos besoins particuliers'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservations::class,
            'max_places' => 100, // Par défaut, peut être override dans le controller
        ]);
    }
}