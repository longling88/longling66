<?php
// 引入同目录下的统一安全配置文件
require_once __DIR__ . '/config.php'; 

// 独立将白名单 IP 数据存储在 whitelist.json
$DATA_FILE  = __DIR__ . '/whitelist.json'; 

// 初始化数据文件
if (!file_exists($DATA_FILE)) {
    file_put_contents($DATA_FILE, json_encode([]));
}

// 获取真实访客 IP
function getRealIp() {
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        return $_SERVER["HTTP_CF_CONNECTING_IP"];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '未知 IP';
}

$current_ip = getRealIp();
$message = '';
$is_verified = false; // ✨ 核心开关：默认验证失败，列表锁死
$input_pass = '';     // 暂存用户填写的密码，体验优化

// 处理表单提交 (加白 / 删除 / 查看)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $input_pass = $_POST['password'] ?? '';

    // ✨ 核心修改：死死校验 IP加白专用密码
    if ($input_pass !== $WHITELIST_PASS) {
        $message = "<div class='alert alert-error'>❌ 专属安全密码错误！验证失败。</div>";
        $input_pass = ''; // 密码错误时清空输入框
    } else {
        $is_verified = true; // ✨ 密码正确！解锁显示列表的权限
        $raw_whitelist = json_decode(file_get_contents($DATA_FILE), true) ?: [];
        
        // ✨ 核心修复：兼容新老数据格式，防止报错
        $whitelist = [];
        $whitelist_ips = [];
        foreach ($raw_whitelist as $item) {
            if (is_string($item)) {
                $whitelist[] = ['ip' => $item, 'time' => '早期记录'];
                $whitelist_ips[] = $item;
            } elseif (is_array($item) && isset($item['ip'])) {
                $whitelist[] = $item;
                $whitelist_ips[] = $item['ip'];
            }
        }

        if ($action === 'add') {
            $new_ip = trim($_POST['ip'] ?? '');
            if (filter_var($new_ip, FILTER_VALIDATE_IP)) {
                if (!in_array($new_ip, $whitelist_ips)) {
                    // 新增时打上时间戳
                    $whitelist[] = ['ip' => $new_ip, 'time' => date('Y-m-d H:i:s')];
                    file_put_contents($DATA_FILE, json_encode(array_values($whitelist), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $message = "<div class='alert alert-success'>✅ 成功将 IP [{$new_ip}] 加入白名单！</div>";
                } else {
                    $message = "<div class='alert alert-warning'>⚠️ 该 IP 已经在白名单中。</div>";
                }
            } else {
                $message = "<div class='alert alert-error'>❌ 请输入有效的 IP 地址！</div>";
            }
        } elseif ($action === 'delete') {
            $del_ip = trim($_POST['ip'] ?? '');
            $whitelist = array_values(array_filter($whitelist, function($item) use ($del_ip) {
                return $item['ip'] !== $del_ip;
            }));
            file_put_contents($DATA_FILE, json_encode($whitelist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $message = "<div class='alert alert-success'>🗑️ 已将 IP [{$del_ip}] 从白名单移除！</div>";
        } elseif ($action === 'view') {
            $message = "<div class='alert alert-success'>🔓 密码验证成功！已为您解锁并显示白名单列表。</div>";
        }
    }
}

// 只有当身份验证通过时，才去读取真实数据发给前端，并且格式化为数组保障不出错
$raw_data = $is_verified ? (json_decode(file_get_contents($DATA_FILE), true) ?: []) : [];
$whitelist_data = [];
foreach ($raw_data as $item) {
    if (is_string($item)) {
        $whitelist_data[] = ['ip' => $item, 'time' => '早期记录'];
    } elseif (is_array($item)) {
        $whitelist_data[] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>快捷安全加白通道</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --border: #cbd5e1;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        body { background: var(--bg-color); color: var(--text-main); padding: 20px; display: flex; justify-content: center; }
        .container { width: 100%; max-width: 500px; margin-top: 40px; }
        .card { background: var(--card-bg); border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); padding: 30px; border: 1px solid #e2e8f0; }
        h3 { font-size: 18px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; justify-content: center;}
        .ip-box { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #475569; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; outline: none; transition: border 0.2s; }
        input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        
        .btn-group { display: flex; gap: 10px; margin-top: 5px; }
        .btn { flex: 1; padding: 12px; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: background 0.2s; }
        .btn:hover { background: var(--primary-hover); }
        .btn-secondary { background: #64748b; }
        .btn-secondary:hover { background: #475569; }

        .alert { padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; font-weight: 500; text-align: center; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .list-title { font-size: 14px; font-weight: 600; margin: 25px 0 10px 0; color: #475569; }
        .ip-list { border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; background: #fff; }
        .ip-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        .ip-item:last-child { border-bottom: none; }
        .ip-value { font-family: monospace; font-size: 15px; display: flex; flex-direction: column; gap: 4px; }
        .ip-value-top { display: flex; align-items: center; gap: 6px; }
        .badge { background: #10b981; color: white; font-size: 11px; padding: 1px 6px; border-radius: 10px; }
        .time-badge { font-size: 11px; color: #94a3b8; }
        .btn-delete { background: none; border: none; color: var(--danger); cursor: pointer; font-size: 13px; font-weight: 500; }
        .btn-delete:hover { color: var(--danger-hover); text-decoration: underline; }
        .empty-tips { padding: 20px; text-align: center; color: #94a3b8; font-size: 13px; }
        .lock-box { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h3>🛡️ 安全加白控制台</h3>
        <p style="text-align: center; font-size: 12px; color: #64748b; margin-top: -15px; margin-bottom: 20px;">(加白后方可正常获批访问后台系统)</p>
        
        <?php if ($message) echo $message; ?>
        
        <div class="ip-box">
            📍 您当前的公网访问 IP：<strong style="font-family: monospace; font-size:15px; color:#1d4ed8;"><?php echo htmlspecialchars($current_ip); ?></strong>
        </div>

        <form method="POST" id="mainForm">
            <input type="hidden" name="action" id="formAction" value="add">
            
            <div class="form-group">
                <label>🔑 验证放行专用安全密码</label>
                <input type="password" name="password" id="sysPassword" value="<?php echo htmlspecialchars($input_pass); ?>" placeholder="请输入专门的IP加白安全密码" required>
            </div>
            
            <div class="form-group">
                <label>🌐 需要解封加白的 IP 地址 (如仅查看可忽略此项)</label>
                <input type="text" name="ip" id="ipInput" value="<?php echo htmlspecialchars($current_ip); ?>" placeholder="例如: 192.168.1.1">
            </div>
            
            <div class="btn-group">
                <button type="submit" class="btn" onclick="document.getElementById('formAction').value='add'; document.getElementById('ipInput').required=true;">🚀 立即加白</button>
                <button type="submit" class="btn btn-secondary" onclick="document.getElementById('formAction').value='view'; document.getElementById('ipInput').required=false;">👁️ 解锁列表</button>
            </div>
        </form>

        <div class="list-title">📊 已授权的白名单明细</div>
        
        <?php if ($is_verified): ?>
            <div class="ip-list">
                <?php if (!empty($whitelist_data)): ?>
                    <?php foreach ($whitelist_data as $item): 
                        $ipStr = $item['ip'] ?? '';
                        $timeStr = $item['time'] ?? '早期记录';
                    ?>
                        <div class="ip-item">
                            <div class="ip-value">
                                <div class="ip-value-top">
                                    <?php echo htmlspecialchars($ip['ip']); ?>
                                    <?php if ($ipStr === $current_ip): ?><span class="badge">您的当前IP</span><?php endif; ?>
                                </div>
                                <span class="time-badge">录入时间: <?php echo htmlspecialchars($timeStr); ?></span>
                            </div>
                            <button type="button" class="btn-delete" onclick="deleteIp('<?php echo htmlspecialchars($ipStr); ?>')">❌ 移除</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-tips">暂无任何白名单规则</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="lock-box">
                <div style="font-size: 32px; margin-bottom: 10px;">🔒</div>
                <div style="color: #64748b; font-size: 13px; font-weight: 500;">列表已高强度加密隐藏</div>
                <div style="color: #94a3b8; font-size: 12px; margin-top: 5px;">请在上方输入正确安全密码后点击【👁️ 解锁列表】查看</div>
            </div>
        <?php endif; ?>

    </div>
</div>

<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="ip" id="deleteIpInput">
    <input type="hidden" name="password" id="deletePwdInput">
</form>

<script>
function deleteIp(ip) {
    const pwd = document.getElementById('sysPassword').value;
    if (!pwd) {
        alert('👉 请先在上方【验证放行专用安全密码】框中输入加白密码，再点击移除！');
        document.getElementById('sysPassword').focus();
        return;
    }
    if (confirm('确定要将该 IP [' + ip + '] 从白名单拦截规则中移除吗？')) {
        document.getElementById('deleteIpInput').value = ip;
        document.getElementById('deletePwdInput').value = pwd;
        document.getElementById('deleteForm').submit();
    }
}
</script>
</body>
</html>
