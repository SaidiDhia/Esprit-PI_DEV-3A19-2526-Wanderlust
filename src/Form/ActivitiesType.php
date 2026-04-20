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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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
                'attr' => ['class' => 'form-control', 'id' => 'activite_categorie'],
                'placeholder' => 'Choisir une catégorie',
            ])
            ->add('type_activite', ChoiceType::class, [
                'choices' => $this->getTypesByCategorie(null),
                'attr' => ['class' => 'form-control', 'id' => 'activite_type'],
                'placeholder' => 'Choisir d\'abord une catégorie',
                'required' => false,
            ])
            ->add('image', FileType::class, [
                'required' => false,
                'mapped'   => false,
                'attr'     => ['class' => 'form-control', 'id' => 'activities_image_input'],
            ])
            ->add('ageMinimum', IntegerType::class, [
                'required' => false,
                'attr' => [
                    'class'       => 'form-control',
                    'placeholder' => 'Âge minimum (optionnel)',
                    'min'         => 0,
                    'max'         => 100,
                ],
                'label' => 'Âge minimum',
            ]);

        // Ajouter un écouteur d'événement pour la catégorie
        $builder->get('categorie')->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event) use ($builder) {
                $form = $event->getForm();
                $categorie = $form->getData();
                
                // Mettre à jour les choix de type_activite
                $builder->add('type_activite', ChoiceType::class, [
                    'choices' => $this->getTypesByCategorie($categorie),
                    'attr' => ['class' => 'form-control', 'id' => 'activite_type'],
                    'placeholder' => 'Choisir un type d\'activité',
                    'required' => false,
                ]);
            }
        );
    }

    private function getTypesByCategorie(?CategorieActiviteEnum $categorie): array
    {
        $typesByCategorie = [
            'desert' => [
                'Quad et buggy 🏎️'               => TypeActiviteEnum::QUAD_BUGGY->value,
                'Trekking dans les dunes 🥾'       => TypeActiviteEnum::TREKKING_DUNES->value,
                'Moto cross désert 🏍️'            => TypeActiviteEnum::MOTO_CROSS_DESERT->value,
                'Balade à dos de dromadaire 🐪'    => TypeActiviteEnum::BALADE_DROMADAIRE->value,
                'Nuit en campement saharien 🏕️'   => TypeActiviteEnum::NUIT_CAMPEMENT->value,
                'Observation des étoiles ⭐'        => TypeActiviteEnum::OBSERVATION_ETOILES->value,
            ],
            'Mer' => [
                'Jet ski 🏄‍♂️'                  => TypeActiviteEnum::JET_SKI->value,
                'Parachute ascensionnel 🪂'         => TypeActiviteEnum::PARACHUTE_ASCENSIONNEL->value,
                'Paddle 🛶'                         => TypeActiviteEnum::PADDLE->value,
                'Kayak 🛶'                          => TypeActiviteEnum::KAYAK->value,
                'Planche à voile ⛵'                => TypeActiviteEnum::PLANCHE_VOILE->value,
                'Plongée sous-marine 🤿'            => TypeActiviteEnum::PLONGEE_SOUS_MARINE->value,
                'Snorkeling 🤽'                     => TypeActiviteEnum::SNORKELING->value,
                'Sortie en bateau ⛵'               => TypeActiviteEnum::SORTIE_BATEAU->value,
                'Pêche touristique 🎣'              => TypeActiviteEnum::PECHE_TOURISTIQUE->value,
            ],
            'Aérien' => [
                'Parachutisme 🪂'                              => TypeActiviteEnum::PARACHUTISME->value,
                'Parapente 🪂'                                 => TypeActiviteEnum::PARAPENTE->value,
                'ULM (Ultra léger motorisé) 🪂'               => TypeActiviteEnum::ULM->value,
                'Montgolfière (occasionnellement dans le sud) 🎈' => TypeActiviteEnum::MONTGOLFIERE->value,
                'Parachute ascensionnel (mer) 🪂'              => TypeActiviteEnum::PARACHUTE_ASCENSIONNEL_MER->value,
            ],
            'nature' => [
                'Randonnée en forêt 🥾' => TypeActiviteEnum::RANDONNEE_FORET->value,
                'Escalade 🧗'           => TypeActiviteEnum::ESCALADE->value,
                'Camping 🏕️'           => TypeActiviteEnum::CAMPING->value,
                'VTT 🚵'               => TypeActiviteEnum::VTT->value,
                'Spéléologie ⛰️'       => TypeActiviteEnum::SPELEOLOGIE->value,
            ],
            'Culture' => [
                'Visite des ksour de Tataouine 🏛️' => TypeActiviteEnum::VISITE_KSOUR->value,
                'Décors de films à Tozeur 🎬'        => TypeActiviteEnum::DECORS_FILMS->value,
                'Visite archéologique 🏺'             => TypeActiviteEnum::VISITE_ARCHEOLOGIQUE->value,
                'Festivals 🎉'                        => TypeActiviteEnum::FESTIVALS->value,
                'Tourisme historique 📚'              => TypeActiviteEnum::TOURISME_HISTORIQUE->value,
                'Photographie 📷'                     => TypeActiviteEnum::PHOTOGRAPHIE->value,
            ],
        ];


        if ($categorie === null) {
            // Retourner tous les types si aucune catégorie n'est sélectionnée
            $allTypes = [];
            foreach ($typesByCategorie as $types) {
                $allTypes = array_merge($allTypes, $types);
            }
            return $allTypes;
        }

        return $typesByCategorie[$categorie->value] ?? [];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Activities::class,
        ]);
    }
}