<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\SaveLog;
use App\Enum\DemoUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Records which values a save changed, read from Doctrine's change set just before the flush.
 */
final readonly class SaveHistory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function record(Product $product, DemoUser $author, string $form, bool $protectionEnabled): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        $changes = [];
        foreach ($unitOfWork->getEntityChangeSet($product) as $field => $change) {
            if (!\is_array($change)) {
                continue;
            }
            [$before, $after] = $change;
            $before = self::display($field, $before);
            $after = self::display($field, $after);
            if ($before !== $after) {
                $changes[] = ['field' => $field, 'before' => $before, 'after' => $after];
            }
        }

        if ([] === $changes) {
            return;
        }

        $this->entityManager->persist(new SaveLog($product, $author, $form, $protectionEnabled, $this->clock->now(), $changes));
    }

    /**
     * @return list<SaveLog>
     */
    public function of(Product $product): array
    {
        /** @var list<SaveLog> */
        return $this->entityManager->createQueryBuilder()
            ->select('log')
            ->from(SaveLog::class, 'log')
            ->where('log.product = :product')
            ->setParameter('product', $product)
            ->orderBy('log.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private static function display(string $field, mixed $value): string
    {
        return match (true) {
            str_ends_with($field, 'InCents') && \is_int($value) => \sprintf('%d.%02d', intdiv($value, 100), abs($value % 100)),
            $value instanceof Category => $value->getName(),
            $value instanceof \BackedEnum => (string) $value->value,
            \is_array($value) => implode(', ', array_map(static fn (mixed $item): string => \is_scalar($item) ? (string) $item : '', $value)),
            \is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
