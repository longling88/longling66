<?php
header('Content-Type: application/json; charset=utf-8');

chdir(__DIR__ . '/../'); // 切回网站根目录

$dataFile = 'data.json';
if (!file_exists($dataFile)) {
    echo json_encode([]);
    exit;
}

$data = json_decode(file_get_contents($dataFile), true);
unset($data['whitelist']); // 保护白名单不泄露

// ==========================================
// 🚀 流量统计与访客日志记录引擎 (支持按小时+通道双重下钻)
// ==========================================
$statsFile = 'stats.json';
$logFile = 'access_logs.txt';

$stats = file_exists($statsFile) ? json_decode(file_get_contents($statsFile), true) : [];
if (!is_array($stats)) $stats = [];

$date = date('Y-m-d');
$hour = date('H'); // 精确到当前小时
$time = date('Y-m-d H:i:s'); // 精确到秒
$code = isset($_GET['code']) && !empty(trim($_GET['code'])) ? trim($_GET['code']) : 'default';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '未知IP';

// 1. 构建 PV/UV 统计结构
if (!isset($stats[$date])) $stats[$date] = ['channels' => [], 'hourly' => []];

// 记录全天该通道维度的流量
if (!isset($stats[$date]['channels'][$code])) $stats[$date]['channels'][$code] = ['pv' => 0, 'uv' => []];
$stats[$date]['channels'][$code]['pv']++;
if (!in_array($ip, $stats[$date]['channels'][$code]['uv'])) {
    $stats[$date]['channels'][$code]['uv'][] = $ip;
}

// 记录该小时该通道维度的流量
if (!isset($stats[$date]['hourly'][$hour])) $stats[$date]['hourly'][$hour] = [];
if (!isset($stats[$date]['hourly'][$hour][$code])) $stats[$date]['hourly'][$hour][$code] = ['pv' => 0, 'uv' => []];
$stats[$date]['hourly'][$hour][$code]['pv']++;
if (!in_array($ip, $stats[$date]['hourly'][$hour][$code]['uv'])) {
    $stats[$date]['hourly'][$hour][$code]['uv'][] = $ip;
}

// 自动清理 30 天前的统计数据，防止文件过大卡顿
$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
foreach ($stats as $k => $v) {
    if ($k < $thirtyDaysAgo) unset($stats[$k]);
}
file_put_contents($statsFile, json_encode($stats), LOCK_EX);

// 2. 追加写入 7 天原始访问日志 (txt格式速度极快)
file_put_contents($logFile, "$time|$ip|$code" . PHP_EOL, FILE_APPEND | LOCK_EX);

echo json_encode($data, JSON_UNESCAPED_UNICODE);
