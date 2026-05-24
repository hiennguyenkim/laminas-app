<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class SystemSettingsTable
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

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1";
        $row = $this->getAdapter()->query($sql)->execute([$key])->current();
        return $row ? (string) $row['setting_value'] : $default;
    }

    public function saveSetting(string $key, string $value): void
    {
        $sqlCheck = "SELECT COUNT(*) as count FROM system_settings WHERE setting_key = ?";
        $row = $this->getAdapter()->query($sqlCheck)->execute([$key])->current();
        $exists = ((int) ($row['count'] ?? 0)) > 0;

        if ($exists) {
            $sqlUpdate = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
            $this->getAdapter()->query($sqlUpdate)->execute([$value, $key]);
        } else {
            $sqlInsert = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)";
            $this->getAdapter()->query($sqlInsert)->execute([$key, $value]);
        }
    }
}
