<?php

namespace App\Form;

use App\Entity\Events;
use App\Entity\Reservations;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ReservationsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $eventId = $options['event_id'] ?? null;
        
        if ($eventId) {
            // Si event_id est fourni, afficher le nom de l'événement en lecture seule
            $eventName = $options['event_name'] ?? '';
            $builder
                ->add('eventDisplay', TextType::class, [
                    'label' => 'Événement',
                    'attr' => [
                        'class' => 'form-control bg-light',
                        'readonly' => true,
                        'value' => $eventName
                    ],
                    'mapped' => false, // Ne pas mapper à l'entité
                    'required' => false
                ]);
        } else {
            // Sinon, afficher la sélection normale
            $builder
                ->add('event', EntityType::class, [
                    'class' => Events::class,
                    'choice_label' => function (Events $event) {
                        return $event->getLieu() . ' - ' . $event->getDateDebut()->format('d/m/Y H:i') . ' (' . $event->getPlacesRestantes() . ' places restantes)';
                    },
                    'label' => 'Événement',
                    'attr' => [
                        'class' => 'form-select'
                    ],
                    'placeholder' => 'Choisissez un événement',
                    'required' => true,
                    'query_builder' => function ($repository) {
                        return $repository->createQueryBuilder('e')
                            ->where('e.date_debut > :now')
                            ->andWhere('e.places_disponibles > 0')
                            ->setParameter('now', new \DateTime())
                            ->orderBy('e.date_debut', 'ASC');
                    }
                ]);
        }

        $builder
            ->add('nomComplet', TextType::class, [
                'label' => 'Nom complet *',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez votre nom complet',
                    'minlength' => 2,
                    'maxlength' => 100,
                    'pattern' => '[a-zA-ZÀ-ÿ\s\-\']{2,100}',
                    'title' => 'Nom complet requis (2-100 caractères, lettres uniquement)'
                ],
                'required' => true
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email *',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'exemple@email.com',
                    'maxlength' => 100,
                    'title' => 'Email valide requis'
                ],
                'required' => true
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '06 12 34 56 78',
                    'pattern' => '[0-9\s\+\.\-\(\)]{10,20}',
                    'title' => 'Format: 06 12 34 56 78 ou +33 6 12 34 56 78',
                    'maxlength' => 20
                ],
                'required' => false
            ])
            ->add('nombrePersonnes', IntegerType::class, [
                'label' => 'Nombre de personnes *',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'max' => 10,
                    'id' => 'nombre_personnes',
                    'title' => 'Nombre de personnes (1-10)'
                ],
                'required' => true
            ])
            ->add('demandesSpeciales', TextareaType::class, [
                'label' => 'Demandes spéciales',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Allergies, besoins spécifiques, etc...',
                    'maxlength' => 1000,
                    'title' => 'Maximum 1000 caractères'
                ],
                'required' => false
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservations::class,
            'event_id' => null,
            'event_name' => null,
        ]);
    }
}
