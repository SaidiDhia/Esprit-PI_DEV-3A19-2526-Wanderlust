<?php

namespace App\Form;

use App\Entity\Activities;
use App\Enum\CategorieActiviteEnum;
use App\Enum\TypeActiviteEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ActivitiesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'attr' => ['class' => 'form-control', 'placeholder' => 'Titre de l\'activité']
            ])
            ->add('description', TextareaType::class, [
                'attr' => ['class' => 'form-control', 'rows' => 5]
            ])
            ->add('categorie', EnumType::class, [
                'class' => CategorieActiviteEnum::class,
                'choice_label' => fn(CategorieActiviteEnum $categorie) => $categorie->getLabel(),
                'attr' => ['class' => 'form-control', 'id' => 'activite_categorie'],  // ← Changé
                'placeholder' => 'Choisir une catégorie',
            ])
            ->add('type_activite', ChoiceType::class, [
                'choices' => [
                    'Quad Buggy 🏜️' => TypeActiviteEnum::QUAD_BUGGY->value,
                    'Moto Cross Désert 🏍️' => TypeActiviteEnum::MOTO_CROSS_DESERT->value,
                    'Balade Dromadaire 🐪' => TypeActiviteEnum::BALADE_DROMADAIRE->value,
                    'Nuit Campement 🏕️' => TypeActiviteEnum::NUIT_CAMPEMENT->value,
                    'Observation Étoiles 🔭' => TypeActiviteEnum::OBSERVATION_ETOILES->value,
                    'Jet Ski 🌊' => TypeActiviteEnum::JET_SKI->value,
                    'Parachute Ascensionnel 🪂' => TypeActiviteEnum::PARACHUTE_ASCENSIONNEL->value,
                    'Paddle 🏄' => TypeActiviteEnum::PADDLE->value,
                    'Kayak 🚣' => TypeActiviteEnum::KAYAK->value,
                    'Planche Voile ⛵' => TypeActiviteEnum::PLANCHE_VOILE->value,
                    'Plongée Sous Marine 🤿' => TypeActiviteEnum::PLONGEE_SOUS_MARINE->value,
                    'Snorkeling 🏊' => TypeActiviteEnum::SNORKELING->value,
                    'Sortie Bateau 🚤' => TypeActiviteEnum::SORTIE_BATEAU->value,
                    'Pêche Touristique 🎣' => TypeActiviteEnum::PECHE_TOURISTIQUE->value,
                    'Parachutisme 🪂' => TypeActiviteEnum::PARACHUTISME->value,
                    'Parapente 🪂' => TypeActiviteEnum::PARAPENTE->value,
                    'ULM ✈️' => TypeActiviteEnum::ULM->value,
                    'Montgolfière 🎈' => TypeActiviteEnum::MONTGOLFIERE->value,
                    'Randonnée Forêt 🌲' => TypeActiviteEnum::RANDONNEE_FORET->value,
                    'Escalade 🧗' => TypeActiviteEnum::ESCALADE->value,
                    'Camping 🏕️' => TypeActiviteEnum::CAMPING->value,
                    'VTT 🚵' => TypeActiviteEnum::VTT->value,
                    'Spéléologie 🕳️' => TypeActiviteEnum::SPELEOLOGIE->value,
                    'Visite Ksour 🏛️' => TypeActiviteEnum::VISITE_KSOUR->value,
                    'Décors Films 🎬' => TypeActiviteEnum::DECORS_FILMS->value,
                    'Visite Archéologique 🏺' => TypeActiviteEnum::VISITE_ARCHEOLOGIQUE->value,
                    'Festivals 🎭' => TypeActiviteEnum::FESTIVALS->value,
                ],
                'attr' => ['class' => 'form-control', 'id' => 'activite_type'],
                'placeholder' => 'Choisir d\'abord une catégorie',
                'required' => false,
            ])
            ->add('image', FileType::class, [
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('ageMinimum', IntegerType::class, [
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Âge minimum (optionnel)',
                    'min' => 0,
                    'max' => 100
                ],
                'label' => 'Âge minimum'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Activities::class,
        ]);
    }
}