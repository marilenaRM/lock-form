<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Product;
use App\Enum\DemoUser;
use App\Form\ProductType;
use App\Service\SaveHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\LiveCollectionTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The same product form as ProductController::edit(), as a LiveComponent: it re-renders, and submits the form,
 * on every change. The product is a LiveProp, so it is reloaded from the database on every request.
 */
#[AsLiveComponent]
final class ProductLiveForm extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    #[LiveProp]
    public ?Product $record = null;

    #[LiveProp]
    public DemoUser $as = DemoUser::Alice;

    #[LiveProp]
    public bool $protection = true;

    #[LiveAction]
    public function save(EntityManagerInterface $entityManager, SaveHistory $history): Response
    {
        $this->submitForm();

        $product = $this->getForm()->getData();
        \assert($product instanceof Product);
        $history->record($product, $this->as, 'live', $this->protection);
        $entityManager->flush();
        $this->addFlash('success', 'product.saved');

        return $this->redirectToRoute('product_live_edit', ['id' => $product->getId(), 'as' => $this->as->value, 'protection' => $this->protection ? '1' : '0']);
    }

    protected function instantiateForm(): FormInterface
    {
        // LiveComponent requests carry their own same-origin check, so the form's CSRF token is not used here.
        return $this->createForm(ProductType::class, $this->record, [
            'csrf_protection' => false,
            'concurrency_protection' => $this->protection,
            'live_collection' => true,
        ]);
    }
}
