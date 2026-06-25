<?php
// ==========================================
// 公共数据接口（供前端 index.html 调用）
// ==========================================

// 关闭错误显示（生产环境）
error_reporting(0);
ini_set('display_errors', 0);

// 允许跨域（如果需要）
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 获取渠道代码
$channel_code = isset($_GET['code']) ? trim($_GET['code']) : '';
if (empty($channel_code)) { $channel_code = 'default'; }

// 读取 data.json
$dataFile = __DIR__ . '/../data.json';   // 假设 index.html 在根目录，admin 在子目录
if (!file_exists($dataFile)) {
    echo json_encode(['error' => '数据文件不存在']);
    exit;
}

$data = json_decode(file_get_contents($dataFile), true);
if (!$data) {
    echo json_encode(['error' => '数据解析失败']);
    exit;
}

// ==========================================
// 统计 PV 和 UV（记录访问日志）
// ==========================================
$logFile = __DIR__ . '/../access_logs.txt';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$now = date('Y-m-d H:i:s');
$logLine = $now . '|' . $ip . '|' . $channel_code . PHP_EOL;

// 简单写入日志（注意并发，使用 FILE_APPEND | LOCK_EX）
file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// ==========================================
// 更新统计 JSON（用于大屏展示）
// ==========================================
$statsFile = __DIR__ . '/../stats.json';
$stats = file_exists($statsFile) ? json_decode(file_get_contents($statsFile), true) : [];

$today = date('Y-m-d');
$hour = date('H');

// 初始化今日结构
if (!isset($stats[$today])) {
    $stats[$today] = ['channels' => [], 'hourly' => []];
}
if (!isset($stats[$today]['channels'][$channel_code])) {
    $stats[$today]['channels'][$channel_code] = ['pv' => 0, 'uv' => []];
}
if (!isset($stats[$today]['hourly'][$hour])) {
    $stats[$today]['hourly'][$hour] = [];
}
if (!isset($stats[$today]['hourly'][$hour][$channel_code])) {
    $stats[$today]['hourly'][$hour][$channel_code] = ['pv' => 0, 'uv' => []];
}

// PV+1
$stats[$today]['channels'][$channel_code]['pv']++;
$stats[$today]['hourly'][$hour][$channel_code]['pv']++;

// UV（去重）
if (!in_array($ip, $stats[$today]['channels'][$channel_code]['uv'])) {
    $stats[$today]['channels'][$channel_code]['uv'][] = $ip;
}
if (!in_array($ip, $stats[$today]['hourly'][$hour][$channel_code]['uv'])) {
    $stats[$today]['hourly'][$hour][$channel_code]['uv'][] = $ip;
}

// 写入 stats.json
file_put_contents($statsFile, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

// ==========================================
// 返回数据给前端
// ==========================================
unset($data['product_channel_mapping']); // 可以不发送映射（前端需要），但前端需要，所以保留

// 只返回必要的字段（但前端需要 mapping，所以保留全部）
echo json_encode($data, JSON_UNESCAPED_UNICODE);
?>
