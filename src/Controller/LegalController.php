<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'app_legal')]
    public function legal(): Response
    {
        return $this->render('legal/page.html.twig', ['title' => 'Mentions légales']);
    }

    #[Route('/conditions-utilisation', name: 'app_terms')]
    public function terms(): Response
    {
        return $this->render('legal/page.html.twig', ['title' => 'Conditions d’utilisation']);
    }
}
