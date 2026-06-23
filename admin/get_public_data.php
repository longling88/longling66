<?php
header('Content-Type: application/json; charset=utf-8');

// 使用绝对路径
$rootDir = realpath(__DIR__ . '/../');
$dataFile = $rootDir . '/data.json';

if (!file_exists($dataFile)) {
    echo json_encode([]);
    exit;
}

$data = json_decode(file_get_contents($dataFile), true);

// 核心安全过滤：剔除白名单列表，不暴露给前端
unset($data['whitelist']);

echo json_encode($data, JSON_UNESCAPED_UNICODE);
?>