<?php

namespace App\Form;

use App\Entity\Posts;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class PostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contenu', TextareaType::class, [
                'constraints' => [
                    new NotBlank(['message' => 'Le contenu ne peut pas être vide.']),
                    new Length([
                        'min'        => 5,
                        'max'        => 2000,
                        'minMessage' => 'Minimum {{ limit }} caractères.',
                        'maxMessage' => 'Maximum {{ limit }} caractères.',
                    ]),
                ],
                'attr' => [
                    'rows'        => 5,
                    'placeholder' => 'Écrivez votre publication...',
                ],
            ])
            ->add('media', FileType::class, [
                'required' => false,
                'mapped'   => false,
                'constraints' => [
                    new File([
                        'maxSize'          => '10M',
                        'maxSizeMessage'   => 'Le fichier est trop volumineux (max 10 Mo).',
                        'mimeTypes'        => [
                            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                            'video/mp4', 'video/avi', 'video/quicktime',
                        ],
                        'mimeTypesMessage' => 'Veuillez uploader une image ou vidéo valide (JPG, PNG, GIF, WebP, MP4, AVI, MOV).',
                    ]),
                ],
            ])
            ->add('statut', ChoiceType::class, [
                'choices' => [
                    'Public'     => 'public',
                    'Privé'      => 'private',
                    'Programmer' => 'scheduled',
                ],
                'data'        => 'public',
                'constraints' => [
                    new NotBlank(['message' => 'Veuillez choisir une visibilité.']),
                ],
            ])
            // FIX: Removed 'min' attr — it caused Chrome/Edge to silently set
            // input.value = "" when the selected datetime was <= min, making
            // the JS validation always fail even with a valid date entered.
            // Future-date validation is now handled entirely in new.html.twig JS.
            ->add('scheduledAt', DateTimeType::class, [
                'required' => false,
                'mapped'   => true,
                'widget'   => 'single_text',
                'html5'    => true,
                'label'    => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Posts::class,
            'csrf_protection' => true,
        ]);
    }
}