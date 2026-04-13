<?php
// src/Form/EventsType.php

namespace App\Form;

use App\Entity\Activities;
use App\Entity\Events;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

class EventsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Lieu + Map
            ->add('lieu', TextType::class, [
                'label'       => 'Lieu',
                'attr'        => [
                    'placeholder'    => 'Entrez une adresse...',
                    'class'          => 'lieu-input',
                    'autocomplete'   => 'off',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Le lieu est obligatoire.']),
                    new Length(['min' => 3, 'max' => 255, 'minMessage' => 'Le lieu doit contenir au moins 3 caractères.', 'maxMessage' => 'Le lieu ne peut pas dépasser 255 caractères.']),
                ],
            ])
            // latitude & longitude sont des champs cachés remplis par la map
            ->add('latitude', TextType::class, [
                'label'  => false,
                'mapped' => false,
                'attr'   => ['id' => 'map-lat', 'class' => 'd-none'],
            ])
            ->add('longitude', TextType::class, [
                'label'  => false,
                'mapped' => false,
                'attr'   => ['id' => 'map-lng', 'class' => 'd-none'],
            ])

            // Dates
            ->add('dateDebut', DateTimeType::class, [
                'label'        => 'Date et heure de début',
                'widget'       => 'single_text',
                'attr'         => ['class' => 'datetime-input'],
                'constraints'  => [new NotBlank(['message' => 'La date de début est obligatoire.'])],
            ])
            ->add('dateFin', DateTimeType::class, [
                'label'        => 'Date et heure de fin',
                'widget'       => 'single_text',
                'attr'         => ['class' => 'datetime-input'],
                'constraints'  => [new NotBlank(['message' => 'La date de fin est obligatoire.'])],
            ])
            ->add('dateLimiteInscription', DateTimeType::class, [
                'label'       => "Date limite d'inscription",
                'widget'      => 'single_text',
                'attr'        => ['class' => 'datetime-input'],
                'constraints' => [new NotBlank(['message' => "La date limite est obligatoire."])],
            ])

            // Infos pratiques
            ->add('prix', MoneyType::class, [
                'label'      => 'Prix (TND)',
                'currency'   => false,
                'attr'       => ['placeholder' => '0.00'],
                'constraints'=> [
                    new NotBlank(['message' => 'Le prix est obligatoire.']),
                    new Positive(['message' => 'Le prix doit être positif.']),
                ],
            ])
            ->add('capaciteMax', NumberType::class, [
                'label'       => 'Capacité maximale',
                'html5'       => true,
                'attr'        => ['placeholder' => 'Nombre de places', 'min' => 1],
                'constraints' => [
                    new NotBlank(['message' => 'La capacité est obligatoire.']),
                    new Positive(['message' => 'La capacité doit être positive.']),
                ],
            ])

            // Organisateur
            ->add('organisateur', TextType::class, [
                'label'       => 'Organisateur',
                'attr'        => ['placeholder' => 'Nom de l\'organisateur'],
                'constraints' => [
                    new NotBlank(['message' => "L'organisateur est obligatoire."]),
                    new Length(['min' => 2, 'max' => 100, 'minMessage' => "Le nom de l'organisateur doit contenir au moins 2 caractères.", 'maxMessage' => "Le nom de l'organisateur ne peut pas dépasser 100 caractères."]),
                ],
            ])
            ->add('telephone', TextType::class, [
                'label'       => 'Téléphone',
                'attr'        => ['placeholder' => '+216 XX XXX XXX'],
                'constraints' => [
                    new NotBlank(['message' => 'Le téléphone est obligatoire.']),
                    new Regex([
                        'pattern' => '/^\+216\s?\d{2}\s?\d{3}\s?\d{3}$/',
                        'message' => 'Le numéro de téléphone doit être au format +216 XX XXX XXX.',
                    ]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'attr'        => ['placeholder' => 'contact@exemple.com'],
                'constraints' => [
                    new NotBlank(['message' => "L'email est obligatoire."]),
                    new Email(['message' => "L'email n'est pas valide."]),
                ],
            ])

            // Médias
            ->add('imagesFiles', FileType::class, [
                'label'       => 'Images',
                'mapped'      => false,  // Important: ne pas mapper automatiquement
                'multiple'    => true,
                'required'    => false,
                'attr'        => ['accept' => 'image/*', 'class' => 'images-input'],
                'constraints' => [
                    new All([
                        'constraints' => [
                            new File([
                                'maxSize'          => '5M',
                                'mimeTypes'        => ['image/jpeg', 'image/png', 'image/webp'],
                                'mimeTypesMessage' => 'Format accepté : JPG, PNG, WEBP.',
                            ]),
                        ],
                    ]),
                ],
            ])
            ->add('video', UrlType::class, [
                'label'    => 'Vidéo (optionnel)',
                'required' => false,
                'attr'     => ['placeholder' => 'https://youtube.com/...'],
                'constraints' => [
                    new Url(['message' => 'Le lien vidéo doit être une URL valide.']),
                ],
            ])

            // Description
            ->add('materielsNecessaires', TextareaType::class, [
                'label' => 'Matériels nécessaires',
                'attr'  => ['placeholder' => 'Listez les matériels requis...', 'rows' => 4],
                'constraints' => [
                    new Length(['max' => 1000, 'maxMessage' => 'La description des matériels ne peut pas dépasser 1000 caractères.']),
                ],
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
