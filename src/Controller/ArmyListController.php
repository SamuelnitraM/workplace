<?php

namespace App\Controller;

use App\Entity\ArmyList;
use App\Repository\ArmyListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/army', name: 'app_army_')]
class ArmyListController extends AbstractController
{
    // Liste des armées de l'utilisateur
    #[Route('/', name: 'index')]
    public function index(ArmyListRepository $armyListRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $lists = $armyListRepository->findBy(
            ['owner' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->render('army/index.html.twig', [
            'lists' => $lists,
        ]);
    }

    // Créer une nouvelle liste
    #[Route('/new', name: 'new')]
    public function new(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if ($request->isMethod('POST')) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            $armyList = new ArmyList();
            $armyList->setName($request->request->get('name'));
            $armyList->setFaction($request->request->get('faction'));
            $armyList->setDetachment($request->request->get('detachment') ?: null);
            $armyList->setOwner($user);
            $armyList->setIsPublic($request->request->get('isPublic') === '1');

            $em->persist($armyList);
            $em->flush();

            $this->addFlash('success', 'Liste d\'armée créée avec succès !');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }

        return $this->render('army/new.html.twig');
    }

    // Voir une liste
    #[Route('/{id}', name: 'show')]
    public function show(int $id, ArmyListRepository $armyListRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $armyList = $armyListRepository->find($id);

        if (!$armyList) {
            throw $this->createNotFoundException('Liste introuvable');
        }

        // Vérifier que c'est bien sa liste ou qu'elle est publique
        if ($armyList->getOwner() !== $user && !$armyList->isPublic()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('army/show.html.twig', [
            'armyList' => $armyList,
            'isOwner' => $armyList->getOwner() === $user,
        ]);
    }

    // Modifier une liste
    #[Route('/{id}/edit', name: 'edit')]
    public function edit(
        int $id,
        Request $request,
        ArmyListRepository $armyListRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $armyList = $armyListRepository->find($id);

        if (!$armyList || $armyList->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            $armyList->setName($request->request->get('name'));
            $armyList->setFaction($request->request->get('faction'));
            $armyList->setDetachment($request->request->get('detachment') ?: null);
            $armyList->setIsPublic($request->request->get('isPublic') === '1');
            $armyList->setUpdatedAt(new \DateTimeImmutable());

            $em->flush();

            $this->addFlash('success', 'Liste mise à jour !');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }

        return $this->render('army/edit.html.twig', [
            'armyList' => $armyList,
        ]);
    }

    // Supprimer une liste
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        int $id,
        ArmyListRepository $armyListRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $armyList = $armyListRepository->find($id);

        if (!$armyList || $armyList->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($armyList);
        $em->flush();

        $this->addFlash('success', 'Liste supprimée.');
        return $this->redirectToRoute('app_army_index');
    }
}