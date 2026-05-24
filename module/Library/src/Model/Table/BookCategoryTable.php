<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class BookCategoryTable
{
    private TableGateway $tableGateway;

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    private function getAdapter(): AdapterInterface
    {
        return $this->tableGateway->getAdapter();
    }

    public function fetchAll(): array
    {
        $sql = "SELECT * FROM book_categories ORDER BY name ASC";
        $results = $this->getAdapter()->query($sql)->execute();
        return iterator_to_array($results);
    }

    public function fetchNames(): array
    {
        $sql = "SELECT name FROM book_categories ORDER BY name ASC";
        $results = $this->getAdapter()->query($sql)->execute();
        $names = [];
        foreach ($results as $row) {
            $names[] = $row['name'];
        }
        return $names;
    }

    public function getByName(string $name): ?array
    {
        $sql = "SELECT id FROM book_categories WHERE name = ? LIMIT 1";
        $row = $this->getAdapter()->query($sql)->execute([$name])->current();
        return $row ? (array) $row : null;
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT name FROM book_categories WHERE id = ? LIMIT 1";
        $row = $this->getAdapter()->query($sql)->execute([$id])->current();
        return $row ? (array) $row : null;
    }

    public function insertCategory(string $name): void
    {
        $sql = "INSERT INTO book_categories (name) VALUES (?)";
        $this->getAdapter()->query($sql)->execute([$name]);
    }

    public function updateCategory(int $id, string $name): void
    {
        $sql = "UPDATE book_categories SET name = ? WHERE id = ?";
        $this->getAdapter()->query($sql)->execute([$name, $id]);
    }

    public function deleteCategory(int $id): void
    {
        $sql = "DELETE FROM book_categories WHERE id = ?";
        $this->getAdapter()->query($sql)->execute([$id]);
    }

    public function getDuplicateCategory(string $name, int $excludeId): ?array
    {
        $sql = "SELECT id FROM book_categories WHERE name = ? AND id != ? LIMIT 1";
        $row = $this->getAdapter()->query($sql)->execute([$name, $excludeId])->current();
        return $row ? (array) $row : null;
    }
}
