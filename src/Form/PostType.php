<?php

namespace App\Form;

use App\Entity\Posts;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
                        'min' => 5,
                        'max' => 2000,
                        'minMessage' => 'Minimum {{ limit }} caractères.',
                        'maxMessage' => 'Maximum {{ limit }} caractères.',
                    ]),
                ],
                'attr' => ['rows' => 5, 'placeholder' => 'Écrivez votre publication...'],
            ])
            ->add('media', FileType::class, [
                'required'    => false,
                'mapped'      => false, // ✅ THE FIX — prevents string→File crash on edit
                'constraints' => [
                    new File([
                        'maxSize'          => '10M',
                        'mimeTypes'        => [
                            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                            'video/mp4', 'video/avi', 'video/quicktime',
                        ],
                        'mimeTypesMessage' => 'Veuillez uploader une image ou vidéo valide.',
                    ]),
                ],
            ])
            ->add('statut', ChoiceType::class, [
                'choices' => [
                    'Public' => 'public',
                    'Privé'  => 'private',
                ],
                'constraints' => [new NotBlank(['message' => 'Veuillez choisir un statut.'])],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => Posts::class,
            'csrf_protection' => true,
        ]);
    }
}