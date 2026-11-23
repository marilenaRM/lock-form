<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Service\DemoReset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo', methods: ['GET'])]
    public function index(
        EntityManagerInterface $entityManager,
        #[MapQueryParameter] bool $protection = true,
        #[MapQueryParameter] bool $live = false,
    ): Response
    {
        $product = $entityManager->createQueryBuilder()
            ->select('product')
            ->from(Product::class, 'product')
            ->orderBy('product.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$product instanceof Product) {
            return $this->render('demo/empty.html.twig');
        }

        return $this->render('demo/index.html.twig', ['product' => $product, 'protection' => $protection, 'live' => $live]);
    }

    #[Route('/reset', name: 'demo_reset', methods: ['POST'])]
    public function reset(
        Request $request,
        DemoReset $reset,
        #[MapQueryParameter] bool $protection = true,
        #[MapQueryParameter] bool $live = false,
    ): RedirectResponse
    {
        if ($this->isCsrfTokenValid('demo_reset', $request->getPayload()->getString('_token'))) {
            $reset();
        }

        return $this->redirectToRoute('demo', ['protection' => $protection ? '1' : '0', 'live' => $live ? '1' : '0']);
    }
}
