<?php

namespace App\Form;

use App\Entity\Commentaires;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class CommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contenu', TextareaType::class, [
                'constraints' => [
                    new NotBlank(['message' => 'Le commentaire ne peut pas être vide.']),
                    new Length([
                        'min' => 2,
                        'max' => 500,
                        'minMessage' => 'Minimum {{ limit }} caractères.',
                        'maxMessage' => 'Maximum {{ limit }} caractères.',
                    ]),
                ],
                'attr' => ['rows' => 3, 'placeholder' => 'Ajoutez un commentaire...'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Commentaires::class,
        ]);
    }
}