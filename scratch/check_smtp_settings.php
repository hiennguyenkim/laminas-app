<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$db = $app->getServiceManager()->get(Laminas\Db\Adapter\AdapterInterface::class);

$stmt = $db->query("SELECT * FROM system_settings WHERE setting_key LIKE 'smtp_%'")->execute();
foreach ($stmt as $row) {
    echo "{$row['setting_key']}: '{$row['setting_value']}'\n";
}
