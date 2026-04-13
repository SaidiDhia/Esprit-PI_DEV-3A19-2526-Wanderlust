<?php

namespace App\Form;

use App\Entity\Activities;
use App\Enum\CategorieActiviteEnum;
use App\Enum\TypeActiviteEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class ActivitiesType extends AbstractType  
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre de l\'activité',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex: Plongée sous-marine à Djerba']
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'attr' => ['class' => 'form-control', 'rows' => 5, 'placeholder' => 'Décrivez l\'activité en détail...']
            ])
            ->add('categorie', EnumType::class, [
                'label' => 'Catégorie',
                'class' => CategorieActiviteEnum::class,
                'choice_label' => fn($choice) => $choice->value,
                'attr' => ['class' => 'form-select']
            ])
            ->add('type_activite', TextType::class, [
                'label' => 'Type d\'activité',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex: Kayak, Randonnée, Plongée...']
            ])
            ->add('image', FileType::class, [
                'label' => 'Image de l\'activité',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-control', 'accept' => 'image/jpeg,image/png,image/gif'],
            ]);
    }
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Activities::class,
        ]);
    }
}
