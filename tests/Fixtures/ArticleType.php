<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Fixtures;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Covers the field kinds whose display goes through a transformer: dates, numbers, related objects,
 * collections, an embedded form and a read-only field. Article::$internalNotes is deliberately absent.
 */
final class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class)
            ->add('publishedAt', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('price', NumberType::class, ['scale' => 2])
            ->add('category', ChoiceType::class, [
                'choices' => self::categories(),
                'choice_value' => static fn (?Category $category): ?string => null === $category ? null : (string) $category->id,
                'choice_label' => 'label',
            ])
            ->add('author', AuthorType::class)
            ->add('tags', CollectionType::class, [
                'entry_type' => TextType::class,
                'allow_add' => true,
                'allow_delete' => true,
            ])
            ->add('reviewedBy', TextType::class, ['disabled' => true]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Article::class]);
    }

    /**
     * New instances on every call, as an ORM would hydrate them on every request.
     *
     * @return list<Category>
     */
    public static function categories(): array
    {
        return [new Category(1, 'News'), new Category(2, 'Tutorial')];
    }
}
