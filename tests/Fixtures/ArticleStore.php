<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Fixtures;

/**
 * Stands in for the database: every request loads its own copy, as a controller re-fetching the record would.
 */
final class ArticleStore
{
    private Article $row;

    public function __construct(Article $row)
    {
        $this->row = clone $row;
    }

    public function load(): Article
    {
        return clone $this->row;
    }

    public function save(Article $article): void
    {
        $this->row = clone $article;
    }

    /**
     * @param \Closure(Article): void $change
     */
    public function changeConcurrently(\Closure $change): void
    {
        $article = $this->load();
        $change($article);
        $this->save($article);
    }
}
