<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\Product;
use App\Enum\ProductStatus;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nothing here knows about the concurrency protection: the bundle adds it to every form.
 * The stock is deliberately absent, it has its own form (StockType).
 */
final class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'product.field.name'])
            ->add('priceInCents', MoneyType::class, ['label' => 'product.field.price', 'currency' => 'EUR', 'input' => 'integer', 'divisor' => 100])
            ->add('status', EnumType::class, [
                'label' => 'product.field.status',
                'class' => ProductStatus::class,
                'choice_label' => static fn (ProductStatus $status): string => $status->labelKey(),
            ])
            ->add('category', EntityType::class, [
                'label' => 'product.field.category',
                'class' => Category::class,
                'choice_label' => 'name',
                'choice_translation_domain' => false,
            ])
            ->add('tags', CollectionType::class, [
                'label' => 'product.field.tags',
                'entry_type' => TextType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
