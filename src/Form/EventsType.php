<?php

namespace App\Form;

use App\Entity\Events;
use App\Entity\Activities;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

class EventsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('activities', EntityType::class, [
                'class' => Activities::class,
                'choice_label' => 'titre',
                'label' => 'Activités associées',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-select-modern form-control-modern',
                    'placeholder' => 'Sélectionnez les activités associées (optionnel)'
                ],
                'help' => 'Sélectionnez une ou plusieurs activités pour cet événement (optionnel)'
            ])
            ->add('lieu', TextType::class, [
                'label' => 'Lieu *',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez le lieu de l\'événement',
                    'required' => 'required'
                ]
            ])
            ->add('date_debut', DateTimeType::class, [
                'label' => 'Date et heure de début *',
                'required' => true,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control',
                    'required' => 'required',
                    'placeholder' => 'JJ/MM/AAAA HH:MM'
                ],
                'help' => 'Format: JJ/MM/AAAA HH:MM (ex: 25/12/2024 14:30)'
            ])
            ->add('date_fin', DateTimeType::class, [
                'label' => 'Date et heure de fin *',
                'required' => true,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control',
                    'required' => 'required',
                    'placeholder' => 'JJ/MM/AAAA HH:MM'
                ],
                'help' => 'Format: JJ/MM/AAAA HH:MM (ex: 25/12/2024 16:30)'
            ])
            ->add('prix', MoneyType::class, [
                'label' => 'Prix (€) *',
                'required' => true,
                'currency' => 'EUR',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '0.00',
                    'required' => 'required'
                ]
            ])
            ->add('capacite_max', NumberType::class, [
                'label' => 'Capacité maximale *',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez la capacité maximale',
                    'min' => 1,
                    'max' => 10000,
                    'required' => 'required'
                ]
            ])
            ->add('places_disponibles', NumberType::class, [
                'label' => 'Places disponibles *',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez le nombre de places disponibles',
                    'min' => 1,
                    'required' => 'required'
                ]
            ])
            ->add('organisateur', TextType::class, [
                'label' => 'Organisateur *',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez le nom de l\'organisateur',
                    'required' => 'required'
                ]
            ])
            ->add('materiels_necessaires', TextareaType::class, [
                'label' => 'Matériels nécessaires *',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Décrivez les matériels nécessaires pour l\'événement',
                    'required' => 'required'
                ]
            ])
            ->add('confirmation_organisateur', CheckboxType::class, [
                'label' => 'Je confirme être l\'organisateur de cet événement *',
                'required' => true,
                'attr' => [
                    'class' => 'form-check-input'
                ]
            ])
            ->add('image', FileType::class, [
                'label' => 'Image *',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'class' => 'form-control',
                    'accept' => 'image/jpeg,image/png,image/gif'
                ],
                'help' => 'Formats acceptés: JPG, PNG, GIF (Max 5MB). Une image est obligatoire.'
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone *',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez le numéro de téléphone',
                    'required' => 'required'
                ]
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email *',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez l\'adresse email',
                    'required' => 'required'
                ]
            ])
            ->add('video_youtube', TextType::class, [
                'label' => 'Vidéo YouTube (URL)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'https://www.youtube.com/watch?v=...'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Events::class,
        ]);
    }
}
