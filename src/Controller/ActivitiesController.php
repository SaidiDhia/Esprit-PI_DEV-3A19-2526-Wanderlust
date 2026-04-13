<?php

namespace App\Controller;

use App\Entity\Activities;
use App\Form\ActivitiesType;
use App\Repository\ActivitiesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/activities')]
class ActivitiesController extends AbstractController
{
    #[Route('/', name: 'app_activities_index', methods: ['GET'])]
    public function index(Request $request, ActivitiesRepository $activitiesRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 9; // 3x3 grid
        
        $activities = $activitiesRepository->findAllWithPagination($page, $limit);
        $totalActivities = $activitiesRepository->countAll();
        $totalPages = ceil($totalActivities / $limit);
        
        return $this->render('activities/index.html.twig', [
            'activities' => $activities,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalActivities' => $totalActivities,
        ]);
    }

    #[Route('/new', name: 'app_activities_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $activity = new Activities();
        $form = $this->createForm(ActivitiesType::class, $activity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Contrôle de saisie personnalisé
            $titre = trim($activity->getTitre());
            $description = trim($activity->getDescription());
            $categorie = $activity->getCategorie();
            $typeActivite = $activity->getTypeActivite();
            $ageMinimum = $activity->getAgeMinimum();
            
            // Validation du titre
            if (empty($titre)) {
                $this->addFlash('error', 'Le titre est obligatoire.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            if (strlen($titre) < 3) {
                $this->addFlash('error', 'Le titre doit contenir au moins 3 caractères.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            if (strlen($titre) > 150) {
                $this->addFlash('error', 'Le titre ne doit pas dépasser 150 caractères.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            // Validation de la description
            if (empty($description)) {
                $this->addFlash('error', 'La description est obligatoire.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            if (strlen($description) < 10) {
                $this->addFlash('error', 'La description doit contenir au moins 10 caractères.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            // Validation de la catégorie
            if (!$categorie) {
                $this->addFlash('error', 'La catégorie est obligatoire.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            // Validation du type d'activité
            if (empty($typeActivite)) {
                $this->addFlash('error', 'Le type d\'activité est obligatoire.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            if (strlen($typeActivite) < 2) {
                $this->addFlash('error', 'Le type d\'activité doit contenir au moins 2 caractères.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            if (strlen($typeActivite) > 100) {
                $this->addFlash('error', 'Le type d\'activité ne doit pas dépasser 100 caractères.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            // Validation de l'âge minimum
            if ($ageMinimum !== null && $ageMinimum < 0) {
                $this->addFlash('error', 'L\'âge minimum ne peut pas être négatif.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            if ($ageMinimum !== null && $ageMinimum > 100) {
                $this->addFlash('error', 'L\'âge minimum ne peut pas dépasser 100 ans.');
                return $this->redirectToRoute('app_activities_new');
            }
            
            // Nettoyage des données
            $activity->setTitre(ucfirst($titre));
            $activity->setDescription(ucfirst($description));
            
            $this->addFlash('info', 'Formulaire soumis et valide');
            
            // Traitement de l'image (une seule image)
            /** @var UploadedFile $imageFile */
            $imageFile = $form->get('image')->getData();

            if ($imageFile) {
                $this->addFlash('info', 'Image détectée: ' . $imageFile->getClientOriginalName());
                $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->getClientOriginalExtension();

                try {
                    $imageFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/uploads/activities',
                        $newFilename
                    );
                    $activity->setImage($newFilename);
                    $this->addFlash('info', 'Image uploadée avec succès');
                } catch (FileException $e) {
                    $this->addFlash('error', 'Erreur lors du téléchargement de l\'image: ' . $e->getMessage());
                }
            } else {
                $this->addFlash('info', 'Aucune image fournie');
            }

            $entityManager->persist($activity);
            $entityManager->flush();

            $this->addFlash('success', '🎉 Activité "' . $activity->getTitre() . '" ajoutée avec succès !');

            return $this->redirectToRoute('app_activities_index');
        } else {
            if ($form->isSubmitted()) {
                $this->addFlash('error', 'Formulaire soumis mais invalide');
                // Afficher les erreurs de validation
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('error', 'Erreur: ' . $error->getMessage());
                }
            }
        }

        return $this->render('activities/new.html.twig', [
            'activity' => $activity,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_activities_show', methods: ['GET'])]
    public function show(Activities $activity): Response
    {
        return $this->render('activities/show.html.twig', [
            'activity' => $activity,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_activities_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Activities $activity, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $form = $this->createForm(ActivitiesType::class, $activity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Contrôle de saisie personnalisé
            $titre = trim($activity->getTitre());
            $description = trim($activity->getDescription());
            $categorie = $activity->getCategorie();
            $typeActivite = $activity->getTypeActivite();
            $ageMinimum = $activity->getAgeMinimum();
            
            // Validation du titre
            if (empty($titre)) {
                $this->addFlash('error', 'Le titre est obligatoire.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            if (strlen($titre) < 3) {
                $this->addFlash('error', 'Le titre doit contenir au moins 3 caractères.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            if (strlen($titre) > 150) {
                $this->addFlash('error', 'Le titre ne doit pas dépasser 150 caractères.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            // Validation de la description
            if (empty($description)) {
                $this->addFlash('error', 'La description est obligatoire.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            if (strlen($description) < 10) {
                $this->addFlash('error', 'La description doit contenir au moins 10 caractères.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            // Validation de la catégorie
            if (!$categorie) {
                $this->addFlash('error', 'La catégorie est obligatoire.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            // Validation du type d'activité
            if (empty($typeActivite)) {
                $this->addFlash('error', 'Le type d\'activité est obligatoire.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            if (strlen($typeActivite) < 2) {
                $this->addFlash('error', 'Le type d\'activité doit contenir au moins 2 caractères.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            if (strlen($typeActivite) > 100) {
                $this->addFlash('error', 'Le type d\'activité ne doit pas dépasser 100 caractères.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            // Validation de l'âge minimum
            if ($ageMinimum !== null && $ageMinimum < 0) {
                $this->addFlash('error', 'L\'âge minimum ne peut pas être négatif.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            if ($ageMinimum !== null && $ageMinimum > 100) {
                $this->addFlash('error', 'L\'âge minimum ne peut pas dépasser 100 ans.');
                return $this->redirectToRoute('app_activities_edit', ['id' => $activity->getId()]);
            }
            
            // Nettoyage des données
            $activity->setTitre(ucfirst($titre));
            $activity->setDescription(ucfirst($description));
            
            /** @var UploadedFile $imageFile */
            $imageFile = $form->get('image')->getData();

            if ($imageFile) {
                $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->getClientOriginalExtension();

                try {
                    $imageFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/uploads/activities',
                        $newFilename
                    );
                    $activity->setImage($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Erreur lors du téléchargement de l\'image.');
                }
            }

            $entityManager->flush();

            $this->addFlash('success', '🎉 Activité "' . $activity->getTitre() . '" modifiée avec succès !');

            return $this->redirectToRoute('app_activities_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('activities/edit.html.twig', [
            'activity' => $activity,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_activities_delete', methods: ['POST'])]
    public function delete(Request $request, Activities $activity, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$activity->getId(), $request->request->get('_token'))) {
            // Supprimer physiquement l'image
            $image = $activity->getImage();
            if ($image) {
                $imagePath = $this->getParameter('kernel.project_dir') . '/public/uploads/activities/' . $image;
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }

            $entityManager->remove($activity);
            $entityManager->flush();
            $this->addFlash('success', 'L\'activité a été supprimée avec succès.');
        }

        return $this->redirectToRoute('app_activities_index', [], Response::HTTP_SEE_OTHER);
    }
}