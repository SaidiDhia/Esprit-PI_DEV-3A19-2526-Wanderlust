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
    public function index(ActivitiesRepository $activitiesRepository): Response
    {
        return $this->render('activities/index.html.twig', [
            'activities' => $activitiesRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_activities_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $this->addFlash('info', 'Controller Activities::new appelé');
        
        $activity = new Activities();
        $form = $this->createForm(ActivitiesType::class, $activity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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

            $this->addFlash('success', 'L\'activité a été créée avec succès.');

            return $this->redirectToRoute('app_activities_index', [], Response::HTTP_SEE_OTHER);
        } else {
            if ($form->isSubmitted()) {
                $this->addFlash('error', 'Formulaire soumis mais invalide');
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('error', $error->getMessage());
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

            $this->addFlash('success', 'L\'activité a été modifiée avec succès.');

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
            // Supprimer physiquement les images
            $images = $activity->getImages();
            if ($images) {
                foreach ($images as $image) {
                    $imagePath = $this->getParameter('kernel.project_dir') . '/public/uploads/activities/' . $image;
                    if (file_exists($imagePath)) {
                        unlink($imagePath);
                    }
                }
            }

            $entityManager->remove($activity);
            $entityManager->flush();
            $this->addFlash('success', 'L\'activité a été supprimée avec succès.');
        }

        return $this->redirectToRoute('app_activities_index', [], Response::HTTP_SEE_OTHER);
    }
}