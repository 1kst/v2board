<?php
/**
 * V2Board 订阅 Webhook 接收器 - 纯文件日志版
 * 只保存到本地文件,不使用 Redis
 */

// ==================== 配置 ====================

// Webhook 安全密钥
define('WEBHOOK_SECRET', 'ssssssss');

// 日志文件路径 (固定在 /www/wwwroot 目录)
define('LOG_FILE_PATH', '/www/wwwroot/subscribe_logs.log');

// 日志格式: json 或 text
define('LOG_FORMAT', 'json');

// ==================== 处理逻辑 ====================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
if ($receivedSecret !== WEBHOOK_SECRET) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!$data || !isset($data['token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

try {
    $token = $data['token'];
    $ip = $data['ip'] ?? '';
    $userAgent = $data['user_agent'] ?? '';
    $path = $data['path'] ?? '';
    $timestamp = $data['timestamp'] ?? time();
    $date = $data['date'] ?? date('Y-m-d');
    
    // ==================== 写入本地日志文件 ====================
    
    if (LOG_FORMAT === 'json') {
        // JSON 格式 - 记录完整的原始数据(包括所有额外字段)
        // 添加服务器时间戳
        $data['server_timestamp'] = time();
        $data['server_datetime'] = date('Y-m-d H:i:s');
        
        $logEntry = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        // 文本格式 - 方便人工查看
        $datetime = date('Y-m-d H:i:s', $timestamp);
        $logEntry = "[{$datetime}] Token: {$token} | IP: {$ip} | Path: {$path} | UA: {$userAgent}\n";
    }
    
    // 写入文件 (追加模式,带文件锁)
    file_put_contents(LOG_FILE_PATH, $logEntry, FILE_APPEND | LOCK_EX);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Recorded',
        'date' => $date
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}
