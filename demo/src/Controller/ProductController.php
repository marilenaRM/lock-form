<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Enum\DemoUser;
use App\Enum\ProductStatus;
use App\Form\ProductFilterType;
use App\Form\ProductType;
use App\Form\StockType;
use App\Service\SaveHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SaveHistory $history,
    ) {
    }

    #[Route('/products', name: 'product_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filter = $this->createForm(ProductFilterType::class)->handleRequest($request);
        $status = $filter->isSubmitted() && $filter->isValid() ? $filter->get('status')->getData() : null;

        $query = $this->entityManager->createQueryBuilder()
            ->select('product', 'category')
            ->from(Product::class, 'product')
            ->join('product.category', 'category')
            ->orderBy('product.name');
        if ($status instanceof ProductStatus) {
            $query->where('product.status = :status')->setParameter('status', $status);
        }

        return $this->render('product/list.html.twig', [
            'filter' => $filter,
            'products' => $query->getQuery()->getResult(),
        ]);
    }

    /**
     * The usual edit action. The record is loaded from the database on every request, which is all the
     * protection needs; `protection=off` only exists so that the demo can show what happens without it.
     */
    #[Route('/products/{id}/edit', name: 'product_edit', methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity] Product $product,
        Request $request,
        #[MapQueryParameter] DemoUser $as = DemoUser::Alice,
        #[MapQueryParameter] bool $protection = true,
    ): Response {
        $form = $this->createForm(ProductType::class, $product, ['concurrency_protection' => $protection])
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->history->record($product, $as, 'product', $protection);
            $this->entityManager->flush();
            $this->addFlash('success', 'product.saved');

            return $this->redirectToRoute('product_edit', ['id' => $product->getId(), 'as' => $as->value, 'protection' => $protection ? '1' : '0']);
        }

        return $this->render('product/edit.html.twig', [
            'form' => $form,
            'product' => $product,
            'as' => $as,
            'protection' => $protection,
        ]);
    }

    /**
     * A second form on the same record that only touches the stock: a change made here is not a conflict
     * for someone editing the product form, since that form does not show the stock.
     */
    #[Route('/products/{id}/stock', name: 'product_stock', methods: ['GET', 'POST'])]
    public function stock(
        #[MapEntity] Product $product,
        Request $request,
        #[MapQueryParameter] DemoUser $as = DemoUser::Bob,
    ): Response {
        $form = $this->createForm(StockType::class, $product)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->history->record($product, $as, 'stock', true);
            $this->entityManager->flush();
            $this->addFlash('success', 'product.saved');

            return $this->redirectToRoute('product_stock', ['id' => $product->getId(), 'as' => $as->value]);
        }

        return $this->render('product/stock.html.twig', ['form' => $form, 'product' => $product, 'as' => $as]);
    }

    #[Route('/products/{id}/history', name: 'product_history', methods: ['GET'])]
    public function history(#[MapEntity] Product $product): Response
    {
        return $this->render('product/history.html.twig', [
            'product' => $product,
            'logs' => $this->history->of($product),
        ]);
    }
}
