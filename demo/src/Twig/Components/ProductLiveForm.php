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
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The same product form as ProductController::edit(), as a LiveComponent: it re-renders, and submits the form,
 * on every change. The product is a LiveProp, so it is reloaded from the database on every request.
 *
 * Only exercised by tests/Integration/LiveComponentConflictTest for now: a page using it would also need
 * the LiveComponent JavaScript (AssetMapper, Stimulus), which the demo does not ship yet.
 */
#[AsLiveComponent]
final class ProductLiveForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?Product $record = null;

    #[LiveProp]
    public DemoUser $as = DemoUser::Alice;

    #[LiveAction]
    public function save(EntityManagerInterface $entityManager, SaveHistory $history): Response
    {
        $this->submitForm();

        $product = $this->getForm()->getData();
        \assert($product instanceof Product);
        $history->record($product, $this->as, 'live', true);
        $entityManager->flush();
        $this->addFlash('success', 'product.saved');

        return $this->redirectToRoute('product_edit', ['id' => $product->getId(), 'as' => $this->as->value]);
    }

    protected function instantiateForm(): FormInterface
    {
        // LiveComponent requests carry their own same-origin check, so the form's CSRF token is not used here.
        return $this->createForm(ProductType::class, $this->record, ['csrf_protection' => false]);
    }
}
