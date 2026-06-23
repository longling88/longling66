<?php
session_start();
require __DIR__ . '/config.php';

$whitelistFile = __DIR__ . '/whitelist.json'; 
$rootDir = realpath(__DIR__ . '/../'); 
$dataFile = $rootDir . '/data.json';
$uploadDir = $rootDir . '/uploads/';

if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
if (!file_exists($whitelistFile)) { file_put_contents($whitelistFile, json_encode([])); }

// 初始化数据
$now = date('Y-m-d H:i:s');
if (!file_exists($dataFile)) {
    $defaultData = [
        'site_info' => ['site_title' => '平台导航主页', 'logo_url' => '', 'logo_text_1' => '欢迎来到', 'logo_text_2' => 'PG235.top', 'welcome_text' => '欢迎光临', 'announcement' => '祝君游戏愉快！', 'carousel_images' => []],
        'footer_info' => ['notice_title' => '谨防AI诈骗', 'notice_content' => '注意网络安全', 'copyright' => '© 2026'],
        'tabs' => [['id' => 'tab1', 'name' => '8NN集团产品', 'max' => 50, 'time' => $now, 'is_default' => true]],
        'products' => ['tab1' => []], 
        'banners' => [
            ['id' => 'b1', 'title' => '优惠活动大厅', 'subtitle' => '各平台活动', 'icon' => '', 'url' => '#', 'time' => $now]
        ], 
        'channels' => [], 
        'product_channel_mapping' => [],
        'mapping_times' => [],
        'carousel_times' => []
    ];
    file_put_contents($dataFile, json_encode($defaultData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 自动迁移老数据，并修复默认分类逻辑
$data = json_decode(file_get_contents($dataFile), true);
$needsSave = false;

if (!isset($data['mapping_times'])) { $data['mapping_times'] = []; $needsSave = true; }
if (!isset($data['carousel_times'])) { $data['carousel_times'] = []; $needsSave = true; }

$defaultTabCount = 0;
if(isset($data['tabs'])) {
    foreach ($data['tabs'] as $k => &$t) {
        if (empty($t['time'])) { $t['time'] = $now; $needsSave = true; }
        // ✨ 自动纠错机制：确保最多只有一个默认板块
        if (!empty($t['is_default'])) {
            $defaultTabCount++;
            if ($defaultTabCount > 1) {
                $t['is_default'] = false; // 发现多余的默认，强制取消
                $needsSave = true;
            }
        }
        if (!empty($data['products'][$t['id']])) {
            foreach ($data['products'][$t['id']] as $idx => &$p) {
                if (empty($p['id'])) { $p['id'] = 'prod_' . uniqid() . rand(1000,9999); $needsSave = true; }
                if (empty($p['time'])) { $p['time'] = $now; $needsSave = true; }
            }
        }
    }
    // 强制保障至少有一个默认板块
    if ($defaultTabCount === 0 && count($data['tabs']) > 0) {
        $data['tabs'][0]['is_default'] = true;
        $needsSave = true;
    }
}
if(isset($data['channels'])) {
    foreach ($data['channels'] as &$ch) {
        if (empty($ch['time'])) { $ch['time'] = $now; $needsSave = true; }
    }
}
if(isset($data['banners'])) {
    foreach ($data['banners'] as $k => &$b) {
        if (empty($b['id'])) { $b['id'] = 'ban_' . uniqid(); $needsSave = true; }
        if (empty($b['time'])) { $b['time'] = $now; $needsSave = true; }
    }
}
if ($needsSave) { file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }

// 白名单升级
$whitelistRaw = json_decode(file_get_contents($whitelistFile), true) ?: [];
$whitelist = [];
$whitelistIps = [];
$wlNeedsSave = false;
foreach ($whitelistRaw as $item) {
    if (is_string($item)) {
        $whitelist[] = ['ip' => $item, 'time' => $now];
        $whitelistIps[] = $item;
        $wlNeedsSave = true;
    } else {
        $whitelist[] = $item;
        $whitelistIps[] = $item['ip'];
    }
}
if ($wlNeedsSave) { file_put_contents($whitelistFile, json_encode($whitelist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }

if (empty($_SESSION['captcha_num1']) || (isset($_GET['action']) && $_GET['action'] === 'logout')) {
    $_SESSION['captcha_num1'] = rand(1, 9);
    $_SESSION['captcha_num2'] = rand(1, 9);
    $_SESSION['captcha_result'] = $_SESSION['captcha_num1'] + $_SESSION['captcha_num2'];
}

function getRealIp() {
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) return $_SERVER["HTTP_CF_CONNECTING_IP"];
    return $_SERVER['REMOTE_ADDR'] ?? '未知IP';
}
$current_ip = getRealIp();

function getImgUrl($path) {
    if (empty($path)) return '';
    if (preg_match('/^https?:\/\//i', $path) || strpos($path, '//') === 0 || strpos($path, '/') === 0) return $path;
    return '/' . ltrim($path, '/');
}

if (!empty($whitelistIps) && !in_array($current_ip, $whitelistIps)) {
    header('HTTP/1.1 403 Forbidden');
    exit("<div style='text-align:center;margin-top:50px;'><h2>403 Forbidden</h2><p>您的 IP不在白名单中</p></div>");
}

$message = '';
$activeModule = $_GET['module'] ?? 'dashboard';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_logged']);
    header('Location: admin.php');
    exit;
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// ==========================================
// 🚀 核心 POST 逻辑处理
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        $captcha = intval($_POST['captcha'] ?? 0);

        if ($captcha !== $_SESSION['captcha_result']) {
            $message = "<div class='alert alert-error'>❌ 验证码错误！</div>";
        } elseif ($user === $ADMIN_USER && $pass === $ADMIN_PASS) {
            $_SESSION['admin_logged'] = true;
            header('Location: admin.php');
            exit;
        } else {
            $message = "<div class='alert alert-error'>❌ 账号或密码错误！</div>";
        }
        $_SESSION['captcha_num1'] = rand(1, 9);
        $_SESSION['captcha_num2'] = rand(1, 9);
        $_SESSION['captcha_result'] = $_SESSION['captcha_num1'] + $_SESSION['captcha_num2'];
    } 
    elseif (!empty($_SESSION['admin_logged'])) {
        $now = date('Y-m-d H:i:s');

        if ($action === 'update_site') {
            $data['site_info']['site_title'] = $_POST['site_title'] ?? '平台导航主页'; 
            $data['site_info']['logo_text_1'] = $_POST['logo_text_1'] ?? '';
            $data['site_info']['logo_text_2'] = $_POST['logo_text_2'] ?? '';
            $data['site_info']['announcement'] = $_POST['announcement'] ?? '';
            $data['footer_info']['notice_title'] = $_POST['notice_title'] ?? '';
            $data['footer_info']['notice_content'] = $_POST['notice_content'] ?? '';
            $data['footer_info']['copyright'] = $_POST['copyright'] ?? '';

            if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExtensions)) {
                    $newLogo = 'logo_' . time() . '.' . $ext;
                    move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploadDir . $newLogo);
                    $data['site_info']['logo_url'] = 'uploads/' . $newLogo;
                }
            } elseif (!empty($_POST['logo_url'])) { $data['site_info']['logo_url'] = $_POST['logo_url']; }
            file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $message = "<div class='alert alert-success'>✅ 全局配置更新成功！</div>";
        }

        if ($action === 'set_default_tab') {
            $tabId = $_POST['tab_id'] ?? '';
            foreach ($data['tabs'] as &$t) { $t['is_default'] = ($t['id'] === $tabId); }
            file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $message = "<div class='alert alert-success'>✅ 已成功更改默认板块！</div>";
        }
        if ($action === 'move_tab_up') {
            $idx = (int)($_POST['index'] ?? -1);
            if ($idx > 0 && isset($data['tabs'][$idx])) {
                $tmp = $data['tabs'][$idx-1]; $data['tabs'][$idx-1] = $data['tabs'][$idx]; $data['tabs'][$idx] = $tmp;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        if ($action === 'move_tab_down') {
            $idx = (int)($_POST['index'] ?? -1); $total = count($data['tabs']);
            if ($idx >= 0 && $idx < $total - 1 && isset($data['tabs'][$idx])) {
                $tmp = $data['tabs'][$idx+1]; $data['tabs'][$idx+1] = $data['tabs'][$idx]; $data['tabs'][$idx] = $tmp;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        if ($action === 'add_tab') {
            $name = trim($_POST['tab_name'] ?? '');
            if ($name) {
                $tabId = 'tab_' . time() . rand(100,999);
                $isDef = (count($data['tabs']) === 0);
                $data['tabs'][] = ['id' => $tabId, 'name' => $name, 'max' => 100, 'time' => $now, 'is_default' => $isDef];
                $data['products'][$tabId] = [];
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 分类添加成功！</div>";
            }
        }
        if ($action === 'edit_tab') {
            $tabId = $_POST['tab_id'] ?? ''; $name = trim($_POST['tab_name'] ?? '');
            if ($tabId && $name) {
                foreach ($data['tabs'] as &$t) { if ($t['id'] === $tabId) { $t['name'] = $name; $t['time'] = $now; break; } }
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 分类名称修改成功！</div>";
            }
        }
        if ($action === 'delete_tab') {
            $tabId = $_POST['tab_id'] ?? '';
            if (count($data['tabs']) <= 1) {
                $message = "<div class='alert alert-error'>❌ 删除失败：系统至少需要保留一个分类板块！</div>";
            } else {
                $isDefault = false;
                foreach ($data['tabs'] as $t) { if ($t['id'] === $tabId && !empty($t['is_default'])) { $isDefault = true; } }
                if ($isDefault) {
                    $message = "<div class='alert alert-error'>❌ 删除失败：默认板块不可删除！请先将其他板块设为默认。</div>";
                } else {
                    foreach ($data['tabs'] as $k => $t) {
                        if ($t['id'] === $tabId) { unset($data['tabs'][$k]); unset($data['products'][$tabId]); break; }
                    }
                    $data['tabs'] = array_values($data['tabs']); 
                    file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $message = "<div class='alert alert-success'>✅ 分类及旗下产品已彻底删除！</div>";
                }
            }
        }

        if ($action === 'move_product_up') {
            $tabId = $_POST['tab_id'] ?? ''; $idx = (int)($_POST['index'] ?? -1);
            if ($idx > 0 && isset($data['products'][$tabId][$idx])) {
                $tmp = $data['products'][$tabId][$idx-1]; $data['products'][$tabId][$idx-1] = $data['products'][$tabId][$idx]; $data['products'][$tabId][$idx] = $tmp;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        if ($action === 'move_product_down') {
            $tabId = $_POST['tab_id'] ?? ''; $idx = (int)($_POST['index'] ?? -1); $total = count($data['products'][$tabId] ?? []);
            if ($idx >= 0 && $idx < $total - 1 && isset($data['products'][$tabId][$idx])) {
                $tmp = $data['products'][$tabId][$idx+1]; $data['products'][$tabId][$idx+1] = $data['products'][$tabId][$idx]; $data['products'][$tabId][$idx] = $tmp;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        if ($action === 'add_product') {
            $tabId = $_POST['tab_id'] ?? 'tab1'; $name = trim($_POST['name'] ?? ''); $url = trim($_POST['url'] ?? ''); $iconPath = trim($_POST['icon_url'] ?? '');
            if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['icon_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExtensions)) {
                    $newFileName = uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['icon_file']['tmp_name'], $uploadDir . $newFileName);
                    $iconPath = 'uploads/' . $newFileName;
                }
            }
            if (!empty($name) && !empty($url)) {
                $data['products'][$tabId][] = ['id' => 'prod_' . uniqid(), 'name' => $name, 'icon' => $iconPath, 'url' => $url, 'time' => $now];
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 产品上架成功！</div>";
            }
        }
        if ($action === 'delete_product') {
            $tabId = $_POST['tab_id'] ?? ''; $index = $_POST['index'] ?? '';
            if (isset($data['products'][$tabId][$index])) {
                $deletedProduct = $data['products'][$tabId][$index];
                if (isset($data['product_channel_mapping'][$deletedProduct['id']])) { unset($data['product_channel_mapping'][$deletedProduct['id']]); }
                array_splice($data['products'][$tabId], $index, 1);
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 产品已彻底删除！</div>";
            }
        }
        if ($action === 'update_product') {
            $tabId = $_POST['tab_id'] ?? ''; $index = $_POST['index'] ?? '';
            if (isset($data['products'][$tabId][$index])) {
                $data['products'][$tabId][$index]['name'] = trim($_POST['name'] ?? '');
                $data['products'][$tabId][$index]['url'] = trim($_POST['url'] ?? '');
                $data['products'][$tabId][$index]['time'] = $now;
                if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['icon_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExtensions)) {
                        $newFileName = uniqid() . '.' . $ext;
                        move_uploaded_file($_FILES['icon_file']['tmp_name'], $uploadDir . $newFileName);
                        $data['products'][$tabId][$index]['icon'] = 'uploads/' . $newFileName;
                    }
                } elseif (!empty($_POST['icon_url'])) { $data['products'][$tabId][$index]['icon'] = trim($_POST['icon_url']); }
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 产品更新成功！</div>";
            }
        }

        if ($action === 'add_banner') {
            $title = trim($_POST['title'] ?? ''); $subtitle = trim($_POST['subtitle'] ?? '');
            $url = trim($_POST['url'] ?? ''); $iconPath = trim($_POST['icon_url'] ?? '');
            if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['icon_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExtensions)) {
                    $newFileName = 'ban_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['icon_file']['tmp_name'], $uploadDir . $newFileName);
                    $iconPath = 'uploads/' . $newFileName;
                }
            }
            if ($title) {
                $data['banners'][] = ['id' => 'ban_'.uniqid(), 'title' => $title, 'subtitle' => $subtitle, 'icon' => $iconPath, 'url' => $url, 'time' => $now];
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 底部链接添加成功！</div>";
            }
        }
        if ($action === 'edit_banner') {
            $idx = $_POST['index'] ?? '';
            if (isset($data['banners'][$idx])) {
                $data['banners'][$idx]['title'] = trim($_POST['title'] ?? '');
                $data['banners'][$idx]['subtitle'] = trim($_POST['subtitle'] ?? '');
                $data['banners'][$idx]['url'] = trim($_POST['url'] ?? '');
                $data['banners'][$idx]['time'] = $now;
                if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['icon_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExtensions)) {
                        $newFileName = 'ban_' . uniqid() . '.' . $ext;
                        move_uploaded_file($_FILES['icon_file']['tmp_name'], $uploadDir . $newFileName);
                        $data['banners'][$idx]['icon'] = 'uploads/' . $newFileName;
                    }
                } elseif (!empty($_POST['icon_url'])) { $data['banners'][$idx]['icon'] = trim($_POST['icon_url']); }
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 底部链接修改成功！</div>";
            }
        }
        if ($action === 'delete_banner') {
            $idx = $_POST['index'] ?? '';
            if (isset($data['banners'][$idx])) {
                array_splice($data['banners'], $idx, 1);
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 底部链接已删除！</div>";
            }
        }
        if ($action === 'move_banner_up') {
            $idx = (int)($_POST['index'] ?? -1);
            if ($idx > 0 && isset($data['banners'][$idx])) {
                $tmp = $data['banners'][$idx-1]; $data['banners'][$idx-1] = $data['banners'][$idx]; $data['banners'][$idx] = $tmp;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        if ($action === 'move_banner_down') {
            $idx = (int)($_POST['index'] ?? -1); $total = count($data['banners'] ?? []);
            if ($idx >= 0 && $idx < $total - 1 && isset($data['banners'][$idx])) {
                $tmp = $data['banners'][$idx+1]; $data['banners'][$idx+1] = $data['banners'][$idx]; $data['banners'][$idx] = $tmp;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        if ($action === 'add_channel') {
            $channel_code = trim($_POST['channel_code'] ?? '');
            $channel_name = trim($_POST['channel_name'] ?? '');
            if (preg_match('/^[A-Za-z0-9]+$/', $channel_code)) {
                if (!isset($data['channels'])) $data['channels'] = [];
                $exists = false;
                foreach ($data['channels'] as $channel) { if ($channel['code'] === $channel_code) { $exists = true; break; } }
                if (!$exists) {
                    $data['channels'][] = ['code' => $channel_code, 'name' => $channel_name, 'time' => $now];
                    file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $message = "<div class='alert alert-success'>✅ 代理通道添加成功！</div>";
                } else { $message = "<div class='alert alert-warning'>⚠️ 该通道代号已存在。</div>"; }
            } else { $message = "<div class='alert alert-error'>❌ 请输入合法的通道代号（英文+数字）。</div>"; }
        }

        if ($action === 'edit_channel') {
            $old_code = $_POST['old_code'] ?? '';
            $channel_name = trim($_POST['channel_name'] ?? '');
            if ($old_code && $channel_name) {
                foreach ($data['channels'] as &$ch) {
                    if ($ch['code'] === $old_code) { $ch['name'] = $channel_name; $ch['time'] = $now; break; }
                }
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 通道名称修改成功！</div>";
            }
        }

        if ($action === 'delete_channel') {
            $del_index = $_POST['channel_index'] ?? '';
            if (isset($data['channels'][$del_index])) {
                $deletedChannel = $data['channels'][$del_index];
                if (isset($data['product_channel_mapping'])) {
                    foreach ($data['product_channel_mapping'] as $productId => $mappings) {
                        unset($data['product_channel_mapping'][$productId][$deletedChannel['code']]);
                    }
                }
                array_splice($data['channels'], $del_index, 1);
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 通道已彻底删除！</div>";
            }
        }

        if ($action === 'move_carousel_up') {
            $idx = (int)($_POST['index'] ?? -1);
            if ($idx > 0 && isset($data['site_info']['carousel_images'][$idx])) {
                $tmp = $data['site_info']['carousel_images'][$idx-1]; $data['site_info']['carousel_images'][$idx-1] = $data['site_info']['carousel_images'][$idx]; $data['site_info']['carousel_images'][$idx] = $tmp;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        if ($action === 'move_carousel_down') {
            $idx = (int)($_POST['index'] ?? -1); $total = count($data['site_info']['carousel_images'] ?? []);
            if ($idx >= 0 && $idx < $total - 1 && isset($data['site_info']['carousel_images'][$idx])) {
                $tmp = $data['site_info']['carousel_images'][$idx+1]; $data['site_info']['carousel_images'][$idx+1] = $data['site_info']['carousel_images'][$idx]; $data['site_info']['carousel_images'][$idx] = $tmp;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        if ($action === 'add_carousel') {
            $carouselUrl = trim($_POST['carousel_url'] ?? '');
            $finalPath = '';
            if (isset($_FILES['carousel_file']) && $_FILES['carousel_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['carousel_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExtensions)) {
                    $newFileName = 'carousel_' . time() . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($_FILES['carousel_file']['tmp_name'], $uploadDir . $newFileName)) {
                        $finalPath = 'uploads/' . $newFileName;
                    }
                }
            } elseif (!empty($carouselUrl)) { $finalPath = $carouselUrl; }

            if (!empty($finalPath)) {
                if (!isset($data['site_info']['carousel_images'])) $data['site_info']['carousel_images'] = [];
                $data['site_info']['carousel_images'][] = $finalPath;
                $data['carousel_times'][$finalPath] = $now;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 轮播图添加成功！</div>";
            }
        }
        if ($action === 'delete_carousel') {
            $del_index = $_POST['carousel_index'] ?? '';
            if (isset($data['site_info']['carousel_images'][$del_index])) {
                $delPath = $data['site_info']['carousel_images'][$del_index];
                unset($data['carousel_times'][$delPath]);
                array_splice($data['site_info']['carousel_images'], $del_index, 1);
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 轮播图已删除！</div>";
            }
        }

        if ($action === 'clear_logs') {
            $keepDays = (int)($_POST['keep_days'] ?? 7);
            $logFile = $rootDir . '/access_logs.txt';
            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $cutoffTime = date('Y-m-d H:i:s', strtotime("-$keepDays days"));
                $keepLines = [];
                foreach ($lines as $line) {
                    $parts = explode('|', $line);
                    if (count($parts) === 3 && $parts[0] >= $cutoffTime) { $keepLines[] = $line; }
                }
                file_put_contents($logFile, implode(PHP_EOL, $keepLines) . (empty($keepLines) ? '' : PHP_EOL), LOCK_EX);
                $message = "<div class='alert alert-success'>✅ 已成功清理 {$keepDays} 天之前的冗余访问日志！</div>";
            }
        }

        if ($action === 'set_channel_mapping') {
            $channel_code = $_POST['channel_code'] ?? '';
            $product_id = $_POST['product_id'] ?? '';
            $channel_domain = trim($_POST['channel_domain'] ?? '');
            if (!empty($channel_domain)) {
                if (!isset($data['product_channel_mapping'])) $data['product_channel_mapping'] = [];
                if (!isset($data['product_channel_mapping'][$product_id])) $data['product_channel_mapping'][$product_id] = [];
                $data['product_channel_mapping'][$product_id][$channel_code] = rtrim($channel_domain, '/');
                $data['mapping_times'][$product_id][$channel_code] = $now;
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 跳转设置成功！</div>";
            }
        }

        if ($action === 'batch_set_channel_mapping') {
            $channel_code = $_POST['channel_code'] ?? '';
            $product_ids = $_POST['product_ids'] ?? [];
            $channel_domains = $_POST['channel_domains'] ?? [];
            if (!empty($product_ids)) {
                if (!isset($data['product_channel_mapping'])) $data['product_channel_mapping'] = [];
                $successCount = 0;
                foreach ($product_ids as $index => $product_id) {
                    if (!empty(trim($channel_domains[$index]))) {
                        $domain = trim($channel_domains[$index]);
                        if (!isset($data['product_channel_mapping'][$product_id])) $data['product_channel_mapping'][$product_id] = [];
                        $data['product_channel_mapping'][$product_id][$channel_code] = rtrim($domain, '/');
                        $data['mapping_times'][$product_id][$channel_code] = $now;
                        $successCount++;
                    }
                }
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 批量设置成功！共更新 {$successCount} 个产品。</div>";
            }
        }

        if ($action === 'delete_channel_mapping') {
            $channel_code = $_POST['channel_code'] ?? '';
            $product_id = $_POST['product_id'] ?? '';
            if (isset($data['product_channel_mapping'][$product_id][$channel_code])) {
                unset($data['product_channel_mapping'][$product_id][$channel_code]);
                unset($data['mapping_times'][$product_id][$channel_code]);
                if (empty($data['product_channel_mapping'][$product_id])) {
                    unset($data['product_channel_mapping'][$product_id]);
                }
                file_put_contents($dataFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $message = "<div class='alert alert-success'>✅ 映射已移除！</div>";
            }
        }

        if ($action === 'add_ip') {
            $new_ip = trim($_POST['ip'] ?? '');
            if (filter_var($new_ip, FILTER_VALIDATE_IP)) {
                if (!in_array($new_ip, $whitelistIps)) {
                    $whitelist[] = ['ip' => $new_ip, 'time' => $now];
                    file_put_contents($whitelistFile, json_encode(array_values($whitelist), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $message = "<div class='alert alert-success'>✅ IP 已加入白名单！</div>";
                }
            }
        }

        if ($action === 'delete_ip') {
            $del_ip = trim($_POST['ip'] ?? '');
            $whitelist = array_values(array_filter($whitelist, function($item) use ($del_ip) { return $item['ip'] !== $del_ip; }));
            file_put_contents($whitelistFile, json_encode($whitelist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $message = "<div class='alert alert-success'>✅ IP 已移除！</div>";
        }

        $data = json_decode(file_get_contents($dataFile), true);
        $whitelistRaw = json_decode(file_get_contents($whitelistFile), true) ?: [];
        $whitelist = [];
        foreach ($whitelistRaw as $item) { $whitelist[] = is_string($item) ? ['ip'=>$item,'time'=>'早期'] : $item; }
    }
}

$allProducts = [];
if (!empty($data['tabs'])) {
    foreach ($data['tabs'] as $t) {
        if (!empty($data['products'][$t['id']])) {
            foreach ($data['products'][$t['id']] as $p) {
                $allProducts[] = ['id' => $p['id'], 'name' => $p['name'], 'url' => $p['url'], 'tab_name' => $t['name']];
            }
        }
    }
}
$totalMappingsCount = 0;
if (!empty($data['product_channel_mapping'])) {
    foreach ($data['product_channel_mapping'] as $pid => $maps) { $totalMappingsCount += count($maps); }
}

$statsFile = $rootDir . '/stats.json';
$statsData = file_exists($statsFile) ? json_decode(file_get_contents($statsFile), true) : [];

function getDayStats($statsData, $dateStr) {
    $pv = 0; $uvList = [];
    if (isset($statsData[$dateStr]['channels'])) {
        foreach ($statsData[$dateStr]['channels'] as $cCode => $cData) {
            $pv += $cData['pv'] ?? 0;
            if (!empty($cData['uv'])) foreach ($cData['uv'] as $u) $uvList[$u] = 1;
        }
    }
    return ['pv' => $pv, 'uv' => count($uvList)];
}
$todayStr = date('Y-m-d');
$yesterdayStr = date('Y-m-d', strtotime('-1 days'));
$dayBeforeStr = date('Y-m-d', strtotime('-2 days'));

$sToday = getDayStats($statsData, $todayStr);
$sYest = getDayStats($statsData, $yesterdayStr);
$sBefo = getDayStats($statsData, $dayBeforeStr);

$chart7Dates = []; $chart7PV = []; $chart7UV = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart7Dates[] = date('m-d', strtotime($d));
    $dS = getDayStats($statsData, $d);
    $chart7PV[] = $dS['pv']; $chart7UV[] = $dS['uv'];
}

$chart24Hours = []; $chart24PV = []; $chart24UV = [];
for ($h = 0; $h <= 23; $h++) {
    $hourStr = str_pad($h, 2, '0', STR_PAD_LEFT);
    $chart24Hours[] = $hourStr . ':00';
    $hPv = 0; $hUvList = [];
    if (isset($statsData[$todayStr]['hourly'][$hourStr])) {
        $hourData = $statsData[$todayStr]['hourly'][$hourStr];
        if (isset($hourData['pv']) && !is_array($hourData['pv'])) {
            $hPv = $hourData['pv'];
            if(!empty($hourData['uv'])) foreach($hourData['uv'] as $u) $hUvList[$u] = 1;
        } else {
            foreach ($hourData as $chCode => $chData) {
                $hPv += $chData['pv'] ?? 0;
                if (!empty($chData['uv'])) foreach ($chData['uv'] as $u) $hUvList[$u] = 1;
            }
        }
    }
    $chart24PV[] = $hPv; $chart24UV[] = count($hUvList);
}

$maxDate = date('Y-m-d');
$minDate = date('Y-m-d', strtotime('-30 days'));
$rankDate = $_GET['rank_date'] ?? $maxDate;
if ($rankDate < $minDate || $rankDate > $maxDate) $rankDate = $maxDate;
$rankHour = $_GET['rank_hour'] ?? 'all';

$rankStats = [];
if ($rankHour === 'all') {
    $rankStats = $statsData[$rankDate]['channels'] ?? [];
} else {
    if (isset($statsData[$rankDate]['hourly'][$rankHour])) {
        $hourData = $statsData[$rankDate]['hourly'][$rankHour];
        if (isset($hourData['pv']) && !is_array($hourData['pv'])) {
            $rankStats = ['老旧版本数据(不支持按通道下钻)' => $hourData];
        } else {
            $rankStats = $hourData;
        }
    }
}

$logFile = $rootDir . '/access_logs.txt';
$logData = [];
$filterChannel = $_GET['log_channel'] ?? '';
$searchIp = trim($_GET['search_ip'] ?? '');

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_reverse($lines) as $line) {
        $parts = explode('|', $line);
        if (count($parts) === 3) {
            list($lTime, $lIp, $lCode) = $parts;
            $matchChannel = ($filterChannel === '' || $filterChannel === $lCode);
            $matchIp = ($searchIp === '' || stripos($lIp, $searchIp) !== false);
            
            if ($matchChannel && $matchIp) {
                $logData[] = ['time' => $lTime, 'ip' => $lIp, 'channel' => $lCode];
            }
        }
    }
    $logData = array_slice($logData, 0, 500); 
}

if (empty($_SESSION['admin_logged'])) {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统安全登录</title>
    <style>
        :root { --primary-color: #10B981; --main-bg: #f3f4f6; --text-main: #1f2937; }
        body { font-family: system-ui, sans-serif; background: var(--main-bg); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        h2 { text-align: center; color: var(--text-main); margin-bottom: 25px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 500; color: #6b7280; margin-bottom: 8px; }
        input { width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; outline: none; }
        input:focus { border-color: var(--primary-color); }
        .captcha-row { display: flex; gap: 10px; align-items: center; }
        .captcha-box { background: #f9fafb; border: 1px solid #e5e7eb; padding: 11px 15px; border-radius: 8px; font-weight: bold; color: var(--primary-color); font-size: 16px; }
        .btn-submit { width: 100%; padding: 12px; background: var(--primary-color); color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: bold; cursor: pointer; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border: 1px solid #fecaca;}
    </style>
</head>
<body>
    <div class="login-box">
        <h2>⚙️ 运营控制台</h2>
        <?php if ($message) echo $message; ?>
        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group"><label>管理员账号</label><input type="text" name="username" required autofocus></div>
            <div class="form-group"><label>安全密码</label><input type="password" name="password" required></div>
            <div class="form-group"><label>计算验证码 (防机器人)</label>
                <div class="captcha-row"><div class="captcha-box"><?php echo $_SESSION['captcha_num1']; ?> + <?php echo $_SESSION['captcha_num2']; ?> = </div><input type="number" name="captcha" style="flex: 1;" required></div>
            </div>
            <button type="submit" class="btn-submit">安全登录系统</button>
        </form>
    </div>
</body>
</html>
<?php exit; } ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理系统</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #10B981; --sidebar-bg: #ffffff; --sidebar-border: #f0f0f0; --main-bg: #f3f4f6; --card-bg: #ffffff; --text-main: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb; --danger-color: #ef4444; --warning-color: #f59e0b; --radius-base: 8px; --shadow-light: 0 1px 3px rgba(0,0,0,0.1); }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background-color: var(--main-bg); color: var(--text-main); font-size: 14px; }
        a { text-decoration: none; color: inherit; } ul { list-style: none; } button { cursor: pointer; font-family: inherit; }
        .app-container { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background-color: var(--sidebar-bg); border-right: 1px solid var(--sidebar-border); display: flex; flex-direction: column; padding: 20px 0; box-shadow: var(--shadow-light); z-index: 10; }
        .logo { height: 60px; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; color: var(--primary-color); margin-bottom: 30px; border-bottom: 1px solid var(--border-color); padding-bottom: 20px; }
        .menu-item { padding: 12px 25px; color: var(--text-secondary); transition: all 0.3s; display: flex; align-items: center; gap: 12px; }
        .menu-item:hover, .menu-item.active { background-color: #ecfdf5; color: var(--primary-color); border-right: 3px solid var(--primary-color); }
        .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .topbar { background-color: var(--card-bg); padding: 15px 30px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; box-shadow: var(--shadow-light); }
        .breadcrumb { font-size: 14px; color: var(--text-secondary); }
        .user-actions { display: flex; align-items: center; gap: 15px; }
        .container { padding: 30px; flex: 1; }
        .card { background-color: var(--card-bg); border-radius: var(--radius-base); box-shadow: var(--shadow-light); padding: 24px; margin-bottom: 24px; }
        .card-header { font-size: 18px; font-weight: 600; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; color: var(--text-main); }
        .help-box { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 15px; border-radius: 4px; color: #1e3a8a; font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-size: 13px; color: var(--text-secondary); font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-base); font-size: 14px; outline: none; }
        .form-control:focus { border-color: var(--primary-color); }
        .btn { padding: 10px 20px; border: none; border-radius: var(--radius-base); font-size: 14px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; cursor: pointer;}
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-warning { background-color: var(--warning-color); color: white; }
        .btn-danger { background-color: var(--danger-color); color: white; }
        .btn-outline { background-color: transparent; border: 1px solid var(--border-color); }
        .custom-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .custom-table th, .custom-table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .custom-table th { background-color: #f9fafb; color: var(--text-secondary); font-weight: 500; text-transform: uppercase; font-size: 12px; }
        .action-btn { padding: 6px 12px; border-radius: var(--radius-base); font-size: 13px; border: 1px solid transparent; cursor: pointer; }
        .btn-sm { padding: 4px 8px; font-size: 12px; } .btn-group { display: flex; gap: 5px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal.active { display: flex; } .modal-content { background-color: white; border-radius: var(--radius-base); padding: 30px; width: 90%; max-width: 600px; }
        .modal-header { font-size: 18px; font-weight: 600; margin-bottom: 20px; }
        .module-content { display: none; } .module-content.active { display: block; }
        .alert { padding: 12px 16px; border-radius: var(--radius-base); margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; }
        .alert-success { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; } .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .search-input { width: 220px; padding: 8px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-base); font-size: 13px; outline: none; }
        .carousel-item { background: white; border: 1px solid var(--border-color); padding: 10px; border-radius: 8px; text-align: center; display: flex; flex-direction: column; align-items: center; position:relative;}
        .carousel-image { width: 100%; height: 120px; object-fit: cover; border-radius: 6px; border: 1px solid #e5e7eb; margin-bottom: 10px; }
        .stats-number { font-size: 24px; font-weight: bold; color: var(--primary-color); }
        .chart-box { position: relative; height: 320px; width: 100%; margin-top: 20px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; }
        .stat-card { background:#f9fafb; border:1px solid #e5e7eb; padding:15px; border-radius:8px; display:flex; flex-direction:column; gap:5px;}
        .stat-title { font-size:13px; color:#6b7280; font-weight:bold;}
        .stat-row { display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #e5e7eb; padding-bottom:5px; margin-bottom:5px;}
        .stat-row:last-child { border:none; margin:0; padding:0;}
    </style>
</head>
<body>

    <div class="app-container">
        <aside class="sidebar">
            <div class="logo"><span>⚙️ 控制台</span></div>
            <nav>
                <ul>
                    <li><a href="?module=dashboard" class="menu-item <?php echo $activeModule === 'dashboard' ? 'active' : ''; ?>"><span>📊</span> <span>数据大屏浏览</span></a></li>
                    <li><a href="?module=traffic_ranking" class="menu-item <?php echo $activeModule === 'traffic_ranking' ? 'active' : ''; ?>"><span>🏆</span> <span>流量风云榜</span></a></li>
                    <li><a href="?module=access_logs" class="menu-item <?php echo $activeModule === 'access_logs' ? 'active' : ''; ?>"><span>👣</span> <span>访客访问日志</span></a></li>
                    <li style="border-top:1px solid #eee; margin:10px 0;"></li>
                    <li><a href="?module=tabs" class="menu-item <?php echo $activeModule === 'tabs' ? 'active' : ''; ?>"><span>🗂️</span> <span>分类板块管理</span></a></li>
                    <li><a href="?module=product" class="menu-item <?php echo $activeModule === 'product' ? 'active' : ''; ?>"><span>🎁</span> <span>产品管理</span></a></li>
                    <li><a href="?module=channels" class="menu-item <?php echo $activeModule === 'channels' ? 'active' : ''; ?>"><span>🔀</span> <span>通道管理</span></a></li>
                    <li><a href="?module=channel_mapping" class="menu-item <?php echo $activeModule === 'channel_mapping' ? 'active' : ''; ?>"><span>🔗</span> <span>通道映射管理</span></a></li>
                    <li><a href="?module=carousel" class="menu-item <?php echo $activeModule === 'carousel' ? 'active' : ''; ?>"><span>🖼️</span> <span>轮播图管理</span></a></li>
                    <li><a href="?module=banners" class="menu-item <?php echo $activeModule === 'banners' ? 'active' : ''; ?>"><span>🔗</span> <span>底部链接编辑</span></a></li>
                    <li><a href="?module=site" class="menu-item <?php echo $activeModule === 'site' ? 'active' : ''; ?>"><span>📝</span> <span>全局配置</span></a></li>
                    <li><a href="?module=whitelist" class="menu-item <?php echo $activeModule === 'whitelist' ? 'active' : ''; ?>"><span>🛡️</span> <span>IP白名单</span></a></li>
                </ul>
            </nav>
        </aside>

        <div class="main-wrapper">
            <header class="topbar">
                <div class="breadcrumb">当前位置：<?php echo $activeModule; ?></div>
                <div class="user-actions">
                    <a href="/" target="_blank" style="color: var(--primary-color); font-weight:bold; margin-right: 15px;">🌐 查看前端主页</a>
                    <a href="?action=logout" style="color: var(--danger-color); font-weight:bold;">🚪 安全退出</a>
                </div>
            </header>

            <main class="container">
                <?php if ($message) echo $message; ?>
                
                <div id="dashboard" class="module-content <?php echo $activeModule === 'dashboard' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">📊 网站流量监控与系统大盘</div>
                        
                        <div class="form-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 20px;">
                            <div class="form-group"><label>分类板块数量</label><div class="stats-number"><?php echo count($data['tabs']??[]); ?></div></div>
                            <div class="form-group"><label>已上架产品总数</label><div class="stats-number"><?php echo count($allProducts); ?></div></div>
                            <div class="form-group"><label>代理商通道数</label><div class="stats-number"><?php echo count($data['channels']??[]); ?></div></div>
                            <div class="form-group"><label>专属通道映射数</label><div class="stats-number"><?php echo $totalMappingsCount; ?></div></div>
                            <div class="form-group"><label>轮播图数量</label><div class="stats-number"><?php echo count($data['site_info']['carousel_images']??[]); ?></div></div>
                            <div class="form-group"><label>底部快捷链接数</label><div class="stats-number"><?php echo count($data['banners']??[]); ?></div></div>
                            <div class="form-group"><label>白名单IP防线数</label><div class="stats-number"><?php echo count($whitelist); ?></div></div>
                            <div class="form-group"><label>您当前的公网IP</label><div style="font-size: 16px; color: var(--text-secondary); margin-top:5px; font-family:monospace; font-weight:bold;"><?php echo htmlspecialchars($current_ip); ?></div></div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:20px;">
                            <div class="stat-card" style="border-top:4px solid #10b981;">
                                <div class="stat-title">📅 今日流量 (<?php echo $todayStr; ?>)</div>
                                <div class="stat-row"><span>总访问量 (PV)</span><span style="font-size:20px; font-weight:bold; color:#10b981;"><?php echo $sToday['pv']; ?></span></div>
                                <div class="stat-row"><span>独立访客 (UV)</span><span style="font-size:20px; font-weight:bold; color:#10b981;"><?php echo $sToday['uv']; ?></span></div>
                            </div>
                            <div class="stat-card" style="border-top:4px solid #3b82f6;">
                                <div class="stat-title">📅 昨日流量 (<?php echo $yesterdayStr; ?>)</div>
                                <div class="stat-row"><span>总访问量 (PV)</span><span style="font-size:20px; font-weight:bold; color:#3b82f6;"><?php echo $sYest['pv']; ?></span></div>
                                <div class="stat-row"><span>独立访客 (UV)</span><span style="font-size:20px; font-weight:bold; color:#3b82f6;"><?php echo $sYest['uv']; ?></span></div>
                            </div>
                            <div class="stat-card" style="border-top:4px solid #8b5cf6;">
                                <div class="stat-title">📅 前日流量 (<?php echo $dayBeforeStr; ?>)</div>
                                <div class="stat-row"><span>总访问量 (PV)</span><span style="font-size:20px; font-weight:bold; color:#8b5cf6;"><?php echo $sBefo['pv']; ?></span></div>
                                <div class="stat-row"><span>独立访客 (UV)</span><span style="font-size:20px; font-weight:bold; color:#8b5cf6;"><?php echo $sBefo['uv']; ?></span></div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="chart-box">
                                <h4 style="margin-bottom: 10px; color: #374151; font-size:14px;">📈 近 7 天走势 (PV / UV)</h4>
                                <canvas id="chart7Days"></canvas>
                            </div>
                            <div class="chart-box">
                                <h4 style="margin-bottom: 10px; color: #374151; font-size:14px;">🕒 今日 24 小时分布</h4>
                                <canvas id="chart24Hours"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="traffic_ranking" class="module-content <?php echo $activeModule === 'traffic_ranking' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">🏆 各通道流量风云榜</div>
                        <form method="GET" style="margin-bottom: 20px; display:flex; align-items:center; gap:15px; background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #e5e7eb;">
                            <input type="hidden" name="module" value="traffic_ranking">
                            <label style="font-weight:bold;">📅 选择日期：</label>
                            <input type="date" name="rank_date" value="<?php echo $rankDate; ?>" min="<?php echo $minDate; ?>" max="<?php echo $maxDate; ?>" class="form-control" style="width:160px;" onchange="this.form.submit()">
                            
                            <label style="font-weight:bold; margin-left:10px;">⏰ 时间段(小时)：</label>
                            <select name="rank_hour" class="form-control" style="width:160px;" onchange="this.form.submit()">
                                <option value="all" <?php if($rankHour==='all') echo 'selected'; ?>>全天总汇总</option>
                                <?php for($i=0; $i<=23; $i++): $hStr = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                <option value="<?php echo $hStr; ?>" <?php if($rankHour===$hStr) echo 'selected'; ?>><?php echo $hStr; ?>:00 - <?php echo $hStr; ?>:59</option>
                                <?php endfor; ?>
                            </select>
                        </form>

                        <table class="custom-table">
                            <thead><tr><th>排名</th><th>推广通道代号</th><th>访问点击量 (PV)</th><th>独立访客 (UV)</th></tr></thead>
                            <tbody>
                                <?php 
                                if (!empty($rankStats)): 
                                    uasort($rankStats, function($a, $b) { return ($b['pv']??0) <=> ($a['pv']??0); });
                                    $rankNum = 1;
                                    foreach ($rankStats as $c_code => $c_data):
                                        if ($c_code === '老旧版本数据(不支持按通道下钻)') continue;
                                        $c_name = ($c_code === 'default') ? '<span style="color:#9ca3af;">散客访问 (无代理后缀)</span>' : '<strong style="color:var(--primary-color);">'.$c_code.'</strong>';
                                ?>
                                <tr>
                                    <td><span style="background:#f3f4f6; padding:2px 8px; border-radius:12px; font-weight:bold; color:#4b5563;">NO.<?php echo $rankNum++; ?></span></td>
                                    <td><?php echo $c_name; ?></td>
                                    <td><span style="background:#eff6ff; color:#2563eb; padding:2px 8px; border-radius:12px; font-weight:bold;"><?php echo $c_data['pv'] ?? 0; ?> 次</span></td>
                                    <td><span style="background:#ecfdf5; color:#059669; padding:2px 8px; border-radius:12px; font-weight:bold;"><?php echo count($c_data['uv'] ?? []); ?> 人</span></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="4" style="text-align:center; padding:30px; color:#9ca3af;">该时间段下暂无数据</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="access_logs" class="module-content <?php echo $activeModule === 'access_logs' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">👣 访客访问日志追踪</div>
                        
                        <div style="background:#fffbeb; border:1px solid #fde68a; padding:15px; border-radius:8px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <strong style="color:#92400e;">手动清理冗余日志：</strong>
                                <span style="color:#b45309; font-size:13px;">（系统会自动清除7天前的日志，您也可以手动精确清理）</span>
                            </div>
                            <form method="POST" style="margin:0; display:flex; gap:10px;" onsubmit="confirmDelete(event, this, '确定执行手动清理吗？<br><br><span style=\'color:#6b7280;\'>过期的日志将永远无法恢复！</span>');">
                                <input type="hidden" name="action" value="clear_logs">
                                <select name="keep_days" class="form-control" style="width:150px; background:white;">
                                    <option value="7">仅保留最近 7 天</option>
                                    <option value="3">仅保留最近 3 天</option>
                                    <option value="2">仅保留最近 2 天</option>
                                    <option value="1">仅保留最近 1 天</option>
                                </select>
                                <button type="submit" class="btn btn-warning">🧹 确认清理</button>
                            </form>
                        </div>

                        <form method="GET" style="margin-bottom: 20px; display:flex; align-items:center; flex-wrap:wrap; gap:15px; background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #e5e7eb;">
                            <input type="hidden" name="module" value="access_logs">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <label style="font-weight:bold;">🔍 筛选通道：</label>
                                <select name="log_channel" class="form-control" style="width:160px;" onchange="this.form.submit()">
                                    <option value="">-- 全部通道 --</option>
                                    <option value="default" <?php if($filterChannel==='default') echo 'selected'; ?>>散客访问</option>
                                    <?php if (!empty($data['channels'])): foreach ($data['channels'] as $ch): ?>
                                        <option value="<?php echo htmlspecialchars($ch['code']); ?>" <?php if($filterChannel===$ch['code']) echo 'selected'; ?>><?php echo htmlspecialchars($ch['code']); ?></option>
                                    <?php endforeach; endif; ?>
                                </select>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <label style="font-weight:bold;">🎯 搜索 IP：</label>
                                <input type="text" name="search_ip" value="<?php echo htmlspecialchars($searchIp); ?>" class="form-control" placeholder="输入完整或部分IP..." style="width:180px;">
                            </div>
                            <button type="submit" class="btn btn-primary" style="padding: 8px 16px;">查询</button>
                            <?php if(!empty($searchIp) || !empty($filterChannel)): ?>
                                <a href="?module=access_logs" class="btn btn-outline" style="padding: 8px 16px; margin-left: auto;">清除条件</a>
                            <?php endif; ?>
                        </form>

                        <table class="custom-table">
                            <thead><tr><th>精确访问时间</th><th>访客 IP 地址</th><th>受访通道代号</th></tr></thead>
                            <tbody>
                                <?php if (!empty($logData)): foreach ($logData as $log): ?>
                                <tr>
                                    <td><span style="color:#6b7280; font-family:monospace;"><?php echo htmlspecialchars($log['time']); ?></span></td>
                                    <td><strong style="color:var(--text-main); font-family:monospace;"><?php echo htmlspecialchars($log['ip']); ?></strong></td>
                                    <td><span style="background:#f3f4f6; color:#4b5563; padding:2px 8px; border-radius:12px; font-weight:bold; font-size:12px;"><?php echo htmlspecialchars($log['channel']); ?></span></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="3" style="text-align:center; padding:30px; color:#9ca3af;">暂无符合条件的访问记录</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="tabs" class="module-content <?php echo $activeModule === 'tabs' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">🗂️ 分类板块管理</div>
                        <div class="help-box" style="margin-bottom:15px;">
                            <strong>默认板块保护：</strong> 系统必须保留至少一个默认板块。被标记为“默认”的板块无法被删除！若要删除，请先将其他板块设为默认。
                        </div>
                        <form method="POST" style="margin-bottom:20px; background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #e5e7eb;">
                            <input type="hidden" name="action" value="add_tab">
                            <label style="display:block; margin-bottom:10px; font-weight:bold;">✨ 添加新分类：</label>
                            <div style="display:flex; gap:10px;">
                                <input type="text" name="tab_name" class="form-control" placeholder="输入新分类名称" required style="max-width:300px;">
                                <button type="submit" class="btn btn-primary">➕ 立即创建</button>
                            </div>
                        </form>
                        <table class="custom-table">
                            <thead><tr><th>默认状态</th><th>分类名称（前端显示）</th><th>内部标识</th><th>创建/修改时间</th><th>操作 & 排序</th></tr></thead>
                            <tbody>
                                <?php $tabCount = count($data['tabs'] ?? []); if (!empty($data['tabs'])): foreach ($data['tabs'] as $idx => $t): ?>
                                <tr>
                                    <td>
                                        <?php if(!empty($t['is_default'])): ?>
                                            <span style="background:#ecfdf5; color:#059669; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:12px;">🟢 当前默认</span>
                                        <?php else: ?>
                                            <form method="POST" style="margin:0;"><input type="hidden" name="action" value="set_default_tab"><input type="hidden" name="tab_id" value="<?php echo $t['id']; ?>"><button type="submit" class="btn btn-outline btn-sm">设为默认</button></form>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:flex; gap:10px; align-items:center;">
                                            <input type="hidden" name="action" value="edit_tab"><input type="hidden" name="tab_id" value="<?php echo $t['id']; ?>">
                                            <input type="text" name="tab_name" class="form-control" value="<?php echo htmlspecialchars($t['name']); ?>" required style="width:160px;">
                                            <button type="submit" class="btn btn-warning btn-sm">保存</button>
                                        </form>
                                    </td>
                                    <td><span style="color:#9ca3af; font-family:monospace; font-size:12px;"><?php echo $t['id']; ?></span></td>
                                    <td><span style="color:#9ca3af; font-size:12px;"><?php echo htmlspecialchars($t['time'] ?? '-'); ?></span></td>
                                    <td>
                                        <div class="btn-group">
                                            <form method="POST" style="margin:0;"><input type="hidden" name="action" value="move_tab_up"><input type="hidden" name="index" value="<?php echo $idx; ?>"><button type="submit" class="action-btn btn-outline btn-sm" <?php if($idx == 0) echo 'disabled style="opacity:0.5;cursor:not-allowed;"'; ?>>⬆️上移</button></form>
                                            <form method="POST" style="margin:0;"><input type="hidden" name="action" value="move_tab_down"><input type="hidden" name="index" value="<?php echo $idx; ?>"><button type="submit" class="action-btn btn-outline btn-sm" <?php if($idx == $tabCount - 1) echo 'disabled style="opacity:0.5;cursor:not-allowed;"'; ?>>⬇️下移</button></form>
                                            
                                            <form method="POST" onsubmit="confirmDelete(event, this, '确定要彻底删除该分类吗？<br><br><span style=\'color:red;font-weight:bold;\'>注意：这将连带删除该分类下的所有产品！</span>');">
                                                <input type="hidden" name="action" value="delete_tab"><input type="hidden" name="tab_id" value="<?php echo $t['id']; ?>">
                                                <button type="submit" class="action-btn btn-danger btn-sm" style="margin-left:5px;" <?php if(!empty($t['is_default']) || $tabCount <= 1) echo 'disabled style="opacity:0.5;cursor:not-allowed;" title="默认板块不可删除"'; ?>>❌删除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?><tr><td colspan="5" style="text-align:center;">暂无分类</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="product" class="module-content <?php echo $activeModule === 'product' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">🎁 添加产品</div>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_product">
                            <div class="form-grid">
                                <div class="form-group"><label>所属板块</label><select name="tab_id" class="form-control" required><?php if (!empty($data['tabs'])): foreach ($data['tabs'] as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option><?php endforeach; else: ?><option value="">请先添加分类</option><?php endif; ?></select></div>
                                <div class="form-group"><label>产品名称</label><input type="text" name="name" class="form-control" required></div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group"><label>平台默认跳转链接</label><input type="text" name="url" class="form-control" required></div>
                                <div class="form-group"><label>产品图标 (电脑上传)</label><input type="file" name="icon_file" class="form-control" accept="image/*"></div>
                            </div>
                            <div class="form-group"><label>或直接输入图标的外链URL</label><input type="text" name="icon_url" class="form-control" placeholder="https://..."></div>
                            <div style="margin-top: 10px;"><button type="submit" class="btn btn-primary">🚀 上架</button></div>
                        </form>
                    </div>
                    <div class="card">
                        <div class="card-header">📋 已上架产品</div>
                        
                        <div style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap;" id="productFilterContainer">
                            <button class="btn btn-primary btn-sm" onclick="filterProducts('all', this)">全部显示</button>
                            <?php if (!empty($data['tabs'])): foreach ($data['tabs'] as $t): ?>
                                <button class="btn btn-outline btn-sm" onclick="filterProducts('<?php echo $t['id']; ?>', this)">所属板块：<?php echo htmlspecialchars($t['name']); ?></button>
                            <?php endforeach; endif; ?>
                        </div>

                        <table class="custom-table">
                            <thead><tr><th>板块</th><th>产品名称</th><th>平台默认链接</th><th>上架/修改时间</th><th>操作 & 排序</th></tr></thead>
                            <tbody>
                                <?php if (!empty($data['tabs'])): foreach ($data['tabs'] as $t): 
                                    $prodCount = count($data['products'][$t['id']] ?? []);
                                    if (!empty($data['products'][$t['id']])): foreach ($data['products'][$t['id']] as $idx => $p): 
                                ?>
                                <tr class="product-row" data-tab="<?php echo $t['id']; ?>">
                                    <td><span style="background:#f3f4f6; padding:3px 8px; border-radius:4px; font-size:12px; color:#4b5563;"><?php echo htmlspecialchars($t['name']); ?></span></td>
                                    <td><div style="display:flex; align-items:center; gap:10px;"><img src="<?php echo htmlspecialchars(getImgUrl($p['icon']??'')); ?>" style="width:30px; height:30px; border-radius:4px; object-fit:cover; border:1px solid #e5e7eb;"><strong style="font-size: 14px;"><?php echo htmlspecialchars($p['name']); ?></strong></div></td>
                                    <td><span style="color:#6b7280; font-size:12px;"><?php echo htmlspecialchars($p['url']); ?></span></td>
                                    <td><span style="color:#9ca3af; font-size:12px;"><?php echo htmlspecialchars($p['time'] ?? '-'); ?></span></td>
                                    <td>
                                        <div class="btn-group">
                                            <button class="action-btn btn-warning btn-sm" onclick="editProduct('<?php echo $t['id']; ?>', <?php echo $idx; ?>, '<?php echo htmlspecialchars($p['name']); ?>', '<?php echo htmlspecialchars($p['url']); ?>', '<?php echo htmlspecialchars($p['icon']??''); ?>')">修改</button>
                                            
                                            <form method="POST" style="margin:0;"><input type="hidden" name="action" value="move_product_up"><input type="hidden" name="tab_id" value="<?php echo $t['id']; ?>"><input type="hidden" name="index" value="<?php echo $idx; ?>"><button type="submit" class="action-btn btn-outline btn-sm" <?php if($idx == 0) echo 'disabled style="opacity:0.5;cursor:not-allowed;"'; ?>>⬆️ 上移</button></form>
                                            <form method="POST" style="margin:0;"><input type="hidden" name="action" value="move_product_down"><input type="hidden" name="tab_id" value="<?php echo $t['id']; ?>"><input type="hidden" name="index" value="<?php echo $idx; ?>"><button type="submit" class="action-btn btn-outline btn-sm" <?php if($idx == $prodCount - 1) echo 'disabled style="opacity:0.5;cursor:not-allowed;"'; ?>>⬇️ 下移</button></form>

                                            <form method="POST" onsubmit="confirmDelete(event, this, '确定彻底删除该产品吗？<br><br><span style=\'color:#6b7280;\'>相关的代理通道映射也会被一并清除。</span>');">
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="tab_id" value="<?php echo $t['id']; ?>">
                                                <input type="hidden" name="index" value="<?php echo $idx; ?>">
                                                <button type="submit" class="action-btn btn-danger btn-sm" style="margin-left:5px;">删除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; endif; endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="channels" class="module-content <?php echo $activeModule === 'channels' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">🔀 代理通道管理</div>
                        <form method="POST" style="margin-bottom:20px;">
                            <input type="hidden" name="action" value="add_channel">
                            <div class="form-grid">
                                <div class="form-group"><label>通道代号（英文/数字）</label><input type="text" name="channel_code" class="form-control" required pattern="[A-Za-z0-9]+"></div>
                                <div class="form-group"><label>代理商名称（备注用）</label><input type="text" name="channel_name" class="form-control" required></div>
                            </div>
                            <button type="submit" class="btn btn-primary">➕ 创建通道</button>
                        </form>
                        <table class="custom-table">
                            <thead><tr><th>通道代号 (推广后缀)</th><th>代理商备注名称</th><th>创建/修改时间</th><th>操作</th></tr></thead>
                            <tbody>
                                <?php if (!empty($data['channels'])): foreach ($data['channels'] as $i => $ch): ?>
                                <tr>
                                    <td><strong style="color:var(--primary-color); font-size:16px;"><?php echo htmlspecialchars($ch['code']); ?></strong></td>
                                    <td>
                                        <form method="POST" style="display:flex; gap:10px; align-items:center;">
                                            <input type="hidden" name="action" value="edit_channel"><input type="hidden" name="old_code" value="<?php echo htmlspecialchars($ch['code']); ?>">
                                            <input type="text" name="channel_name" class="form-control" value="<?php echo htmlspecialchars($ch['name']); ?>" required style="width:150px;">
                                            <button type="submit" class="btn btn-warning btn-sm">修改名称</button>
                                        </form>
                                    </td>
                                    <td><span style="color:#9ca3af; font-size:12px;"><?php echo htmlspecialchars($ch['time'] ?? '-'); ?></span></td>
                                    <td><form method="POST" onsubmit="confirmDelete(event, this, '确定删除此代理通道吗？<br><br><span style=\'color:red;font-weight:bold;\'>注意：所有使用该通道专属链接的配置将会失效！</span>');"><input type="hidden" name="action" value="delete_channel"><input type="hidden" name="channel_index" value="<?php echo $i; ?>"><button type="submit" class="action-btn btn-danger btn-sm">删除</button></form></td>
                                </tr>
                                <?php endforeach; else: ?><tr><td colspan="4" style="text-align:center;">暂无通道配置</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="channel_mapping" class="module-content <?php echo $activeModule === 'channel_mapping' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">🔗 通道映射管理</div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <div style="display:flex; gap:10px;"><button type="button" class="btn btn-primary" onclick="openModal('addMappingModal')">➕ 新增产品映射</button><button type="button" class="btn btn-warning" onclick="showBatchMappingModal()">📦 批量设置映射</button></div>
                            <div style="display:flex; gap:10px;"><input type="text" id="searchAgent" class="search-input" placeholder="🔍 搜索代理通道..." onkeyup="filterMappingTable()"><input type="text" id="searchDomain" class="search-input" placeholder="🔍 搜索跳转域名..." onkeyup="filterMappingTable()"></div>
                        </div>
                        <table class="custom-table">
                            <thead><tr><th>产品名称</th><th>代理通道</th><th>专属跳转域名</th><th>最后配置时间</th><th>操作</th></tr></thead>
                            <tbody id="mappingTableBody">
                                <?php
                                $hasMapping = false;
                                if (!empty($allProducts)):
                                    foreach ($allProducts as $product):
                                        $mappings = $data['product_channel_mapping'][$product['id']] ?? [];
                                        if (!empty($mappings)):
                                            foreach ($mappings as $channelCode => $mappedLink):
                                                $hasMapping = true;
                                                $mTime = $data['mapping_times'][$product['id']][$channelCode] ?? '早期配置';
                                ?>
                                <tr class="mapping-row">
                                    <td><span style="color:#9ca3af; font-size:12px;">[<?php echo htmlspecialchars($product['tab_name']); ?>]</span> <strong style="font-size: 14px;"><?php echo htmlspecialchars($product['name']); ?></strong></td>
                                    <td class="cell-agent"><span style="background:#eff6ff; color:#2563eb; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:12px;"><?php echo htmlspecialchars($channelCode); ?></span></td>
                                    <td class="cell-domain"><a href="<?php echo htmlspecialchars($mappedLink); ?>" target="_blank" style="color:#10B981; font-weight:500; font-size:13px; text-decoration:underline;"><?php echo htmlspecialchars($mappedLink); ?></a></td>
                                    <td><span style="color:#9ca3af; font-size:12px;"><?php echo htmlspecialchars($mTime); ?></span></td>
                                    <td>
                                        <div class="btn-group">
                                            <button class="action-btn btn-outline btn-sm" onclick="showProductMappingModal('<?php echo $product['id']; ?>', '<?php echo htmlspecialchars($product['name']); ?>')">➕ 其他通道</button>
                                            <form method="POST" onsubmit="confirmDelete(event, this, '确定移除此代理映射关系吗？');">
                                                <input type="hidden" name="action" value="delete_channel_mapping">
                                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                <input type="hidden" name="channel_code" value="<?php echo htmlspecialchars($channelCode); ?>">
                                                <button type="submit" class="action-btn btn-danger btn-sm">删除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; endif; endforeach; endif; if (!$hasMapping): ?>
                                <tr><td colspan="5" style="text-align:center; padding: 40px; color:#9ca3af;">暂无任何通道映射，请点击上方【➕ 新增产品映射】按钮添加。</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="carousel" class="module-content <?php echo $activeModule === 'carousel' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">🖼️ 轮播图管理</div>
                        <form method="POST" enctype="multipart/form-data" style="background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #e5e7eb; margin-bottom:20px;">
                            <input type="hidden" name="action" value="add_carousel">
                            <div class="form-grid" style="margin-bottom:10px;">
                                <div class="form-group"><label>方式一：电脑上传</label><input type="file" name="carousel_file" class="form-control" accept="image/*"></div>
                                <div class="form-group"><label>方式二：填图片外链URL</label><input type="text" name="carousel_url" class="form-control" placeholder="https://..."></div>
                            </div>
                            <button type="submit" class="btn btn-primary">➕ 上传 / 添加</button>
                        </form>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px;">
                            <?php $carCount = count($data['site_info']['carousel_images'] ?? []); if ($carCount > 0): foreach ($data['site_info']['carousel_images'] as $i => $img): 
                                $cTime = $data['carousel_times'][$img] ?? '-';
                            ?>
                                <div class="carousel-item">
                                    <img src="<?php echo htmlspecialchars(getImgUrl($img)); ?>" class="carousel-image">
                                    <span style="color:#9ca3af; font-size:11px; margin-bottom:8px;">上传时间: <?php echo htmlspecialchars($cTime); ?></span>
                                    <div style="display:flex; width:100%; gap:5px; margin-bottom:5px;">
                                        <form method="POST" style="flex:1;"><input type="hidden" name="action" value="move_carousel_up"><input type="hidden" name="index" value="<?php echo $i; ?>"><button type="submit" class="btn btn-outline" style="width:100%; padding:4px;" <?php if($i == 0) echo 'disabled style="opacity:0.5;cursor:not-allowed;"'; ?>>⬅️ 前移</button></form>
                                        <form method="POST" style="flex:1;"><input type="hidden" name="action" value="move_carousel_down"><input type="hidden" name="index" value="<?php echo $i; ?>"><button type="submit" class="btn btn-outline" style="width:100%; padding:4px;" <?php if($i == $carCount - 1) echo 'disabled style="opacity:0.5;cursor:not-allowed;"'; ?>>➡️ 后移</button></form>
                                    </div>
                                    <form method="POST" onsubmit="confirmDelete(event, this, '确定彻底删除这张轮播图吗？');" style="width:100%;">
                                        <input type="hidden" name="action" value="delete_carousel"><input type="hidden" name="carousel_index" value="<?php echo $i; ?>">
                                        <button type="submit" class="action-btn btn-danger" style="width:100%;">🗑️ 彻底删除</button>
                                    </form>
                                </div>
                            <?php endforeach; else: ?><div style="grid-column:1/-1; padding:20px; text-align:center;">暂无轮播图片</div><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="banners" class="module-content <?php echo $activeModule === 'banners' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">🔗 底部快捷链接编辑</div>
                        
                        <form method="POST" enctype="multipart/form-data" style="background:#eff6ff; padding:15px; border-radius:8px; border:1px solid #bfdbfe; margin-bottom:20px;">
                            <input type="hidden" name="action" value="add_banner">
                            <label style="display:block; margin-bottom:10px; font-weight:bold; color:#1e40af;">➕ 添加新的横幅链接：</label>
                            <div class="form-grid" style="margin-bottom: 10px;">
                                <div class="form-group"><label>标题</label><input type="text" name="title" class="form-control" required placeholder="如：优惠大厅"></div>
                                <div class="form-group"><label>副标题</label><input type="text" name="subtitle" class="form-control" placeholder="如：点击进入"></div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group"><label>跳转域名 (留空则不跳)</label><input type="text" name="url" class="form-control" placeholder="https://..."></div>
                                <div class="form-group"><label>左侧图标 (本地上传)</label><input type="file" name="icon_file" class="form-control" accept="image/*"></div>
                            </div>
                            <button type="submit" class="btn btn-primary" style="margin-top:10px;">🚀 创建新横幅</button>
                        </form>

                        <div style="border-top:1px solid #e5e7eb; margin:20px 0;"></div>

                        <?php $banCount = count($data['banners'] ?? []); if ($banCount > 0): foreach ($data['banners'] as $i => $b): ?>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="edit_banner">
                            <input type="hidden" name="index" value="<?php echo $i; ?>">
                            <div style="background:#f9fafb; padding:15px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:15px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                    <h4 style="color:var(--primary-color);">底部横幅 NO.<?php echo $i + 1; ?> <span style="font-size:12px; color:#9ca3af; font-weight:normal; margin-left:10px;">(上次编辑: <?php echo htmlspecialchars($b['time'] ?? '-'); ?>)</span></h4>
                                    <div class="btn-group">
                                        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('form_up_ban_<?php echo $i; ?>').submit()" <?php if($i == 0) echo 'disabled style="opacity:0.5;"'; ?>>⬆️上移</button>
                                        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('form_down_ban_<?php echo $i; ?>').submit()" <?php if($i == $banCount - 1) echo 'disabled style="opacity:0.5;"'; ?>>⬇️下移</button>
                                        <button type="button" class="btn-sm btn-danger" onclick="confirmDelete(event, document.getElementById('form_del_ban_<?php echo $i; ?>'), '确定彻底删除该底部横幅吗？')">❌删除</button>
                                    </div>
                                </div>
                                <div class="form-grid" style="margin-bottom: 10px;">
                                    <div class="form-group"><label>标题</label><input type="text" name="title" value="<?php echo htmlspecialchars($b['title']); ?>" class="form-control" required></div>
                                    <div class="form-group"><label>副标题 (小字)</label><input type="text" name="subtitle" value="<?php echo htmlspecialchars($b['subtitle']??''); ?>" class="form-control"></div>
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>左侧图标 (支持本地上传)</label>
                                        <input type="file" name="icon_file" class="form-control" accept="image/*">
                                        <?php if(!empty($b['icon'])): ?><div style="margin-top:5px;"><img src="<?php echo htmlspecialchars(getImgUrl($b['icon'])); ?>" style="width:30px;height:30px;border-radius:4px;object-fit:cover;border:1px solid #ccc;"></div><?php endif; ?>
                                    </div>
                                    <div class="form-group"><label>或外链图标 (URL)</label><input type="text" name="icon_url" value="<?php echo htmlspecialchars($b['icon']); ?>" class="form-control"></div>
                                </div>
                                <div class="form-group" style="margin-top: 10px;"><label>被点击时跳转的域名 (留空则不跳)</label><input type="text" name="url" value="<?php echo htmlspecialchars($b['url']); ?>" class="form-control"></div>
                                <div style="text-align: right; margin-top:10px;"><button type="submit" class="btn btn-warning">💾 保存此横幅修改</button></div>
                            </div>
                        </form>
                        <form method="POST" id="form_up_ban_<?php echo $i; ?>"><input type="hidden" name="action" value="move_banner_up"><input type="hidden" name="index" value="<?php echo $i; ?>"></form>
                        <form method="POST" id="form_down_ban_<?php echo $i; ?>"><input type="hidden" name="action" value="move_banner_down"><input type="hidden" name="index" value="<?php echo $i; ?>"></form>
                        <form method="POST" id="form_del_ban_<?php echo $i; ?>"><input type="hidden" name="action" value="delete_banner"><input type="hidden" name="index" value="<?php echo $i; ?>"></form>
                        <?php endforeach; else: ?><div style="text-align:center; color:#9ca3af; padding:20px;">暂无底部横幅</div><?php endif; ?>
                    </div>
                </div>

                <div id="site" class="module-content <?php echo $activeModule === 'site' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">📝 全局配置</div>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_site">
                            <div class="form-group" style="margin-bottom:15px;"><label>网站浏览器网页标题 (Title)</label><input type="text" name="site_title" value="<?php echo htmlspecialchars($data['site_info']['site_title'] ?? '平台导航主页'); ?>" class="form-control" required></div>
                            <div class="form-grid">
                                <div class="form-group"><label>主标文字一 (左上角)</label><input type="text" name="logo_text_1" value="<?php echo htmlspecialchars($data['site_info']['logo_text_1']); ?>" class="form-control"></div>
                                <div class="form-group"><label>主标文字二 (带颜色字)</label><input type="text" name="logo_text_2" value="<?php echo htmlspecialchars($data['site_info']['logo_text_2']); ?>" class="form-control"></div>
                            </div>
                            <div class="form-group"><label>滚动公告</label><input type="text" name="announcement" value="<?php echo htmlspecialchars($data['site_info']['announcement']); ?>" class="form-control"></div>
                            <div class="form-group" style="margin-top:10px;">
                                <label>左上角LOGO上传 (直接上传新图覆盖)</label>
                                <?php if(!empty($data['site_info']['logo_url'])): ?><div style="margin-bottom:5px;"><img src="<?php echo htmlspecialchars(getImgUrl($data['site_info']['logo_url'])); ?>" style="height:40px; background:#f3f4f6; border-radius:4px; padding:2px; border:1px solid #e5e7eb;"></div><?php endif; ?>
                                <input type="file" name="logo_file" accept="image/*" class="form-control">
                            </div>
                            <div class="form-group"><label>或填写LOGO外链地址</label><input type="text" name="logo_url" value="<?php echo htmlspecialchars(getImgUrl($data['site_info']['logo_url'])); ?>" class="form-control"></div>
                            <hr style="border:0; border-top:1px solid var(--border-color); margin:25px 0;">
                            <div class="form-group"><label>防骗提示标题</label><input type="text" name="notice_title" value="<?php echo htmlspecialchars($data['footer_info']['notice_title']); ?>" class="form-control"></div>
                            <div class="form-group"><label>防骗内容</label><textarea name="notice_content" class="form-control" rows="3"><?php echo htmlspecialchars($data['footer_info']['notice_content']); ?></textarea></div>
                            <div class="form-group"><label>版权</label><input type="text" name="copyright" value="<?php echo htmlspecialchars($data['footer_info']['copyright']); ?>" class="form-control"></div>
                            <div style="margin-top: 20px;"><button type="submit" class="btn btn-primary">保存配置</button></div>
                        </form>
                    </div>
                </div>

                <div id="whitelist" class="module-content <?php echo $activeModule === 'whitelist' ? 'active' : ''; ?>">
                    <div class="card">
                        <div class="card-header">🛡️ IP白名单管理 (安全防线)</div>
                        <form method="POST" style="display:flex; gap:12px; margin-bottom:20px;">
                            <input type="hidden" name="action" value="add_ip"><input type="text" name="ip" class="form-control" placeholder="输入要强制放行的IP" required><button type="submit" class="btn btn-primary">➕ 加白</button>
                        </form>
                        <table class="custom-table">
                            <thead><tr><th>允许访问的 IP 地址</th><th>录入时间</th><th>操作</th></tr></thead>
                            <tbody>
                                <?php if (!empty($whitelist)): foreach ($whitelist as $item): ?>
                                    <tr>
                                        <td><strong style="font-family: monospace; font-size: 15px; color:var(--primary-color);"><?php echo htmlspecialchars($item['ip']); ?></strong></td>
                                        <td><span style="color:#9ca3af; font-size:12px;"><?php echo htmlspecialchars($item['time']); ?></span></td>
                                        <td>
                                            <form method="POST" style="margin:0;" onsubmit="confirmDelete(event, this, '确定移除该 IP 的白名单权限吗？<br><br><span style=\'color:red;font-weight:bold;\'>注意：如果这是您自己的 IP，移除后您将立即被踢出后台！</span>');">
                                                <input type="hidden" name="action" value="delete_ip"><input type="hidden" name="ip" value="<?php echo htmlspecialchars($item['ip']); ?>">
                                                <button type="submit" class="action-btn btn-danger">移除拦截</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="3" style="text-align:center; padding:30px; color:#9ca3af;">当前没有任何白名单，只要知道密码任何人都能登录</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <div id="deleteConfirmModal" class="modal">
        <div class="modal-content" style="max-width: 400px; text-align: center; padding-top: 40px;">
            <div style="font-size: 48px; margin-bottom: 15px;">⚠️</div>
            <h3 style="color: var(--danger-color); margin-bottom: 15px;">高危操作确认</h3>
            <p id="deleteConfirmMessage" style="margin-bottom: 30px; color: var(--text-secondary); line-height: 1.6; font-size: 15px;"></p>
            <div class="modal-actions" style="justify-content: center; gap: 15px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteConfirmModal')">点错了，取消</button>
                <button type="button" class="btn btn-danger" onclick="executeDelete()">确认操作</button>
            </div>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content"><div class="modal-header">✏️ 编辑产品</div><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="update_product"><input type="hidden" name="tab_id" id="editTabId"><input type="hidden" name="index" id="editIndex"><div class="form-group"><label>产品名称</label><input type="text" name="name" id="editName" class="form-control" required></div><div class="form-group"><label>平台默认跳转链接</label><input type="text" name="url" id="editUrl" class="form-control" required></div><div class="form-group"><label>更换图标 (选填)</label><input type="file" name="icon_file" class="form-control" accept="image/*"></div><div class="form-group"><label>外链图标</label><input type="text" name="icon_url" id="editIconUrl" class="form-control"></div><div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeModal('editModal')">取消</button><button type="submit" class="btn btn-primary">保存修改</button></div></form></div>
    </div>

    <div id="addMappingModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">➕ 新增产品映射</div>
            <form method="POST">
                <input type="hidden" name="action" value="set_channel_mapping">
                <div class="form-group">
                    <label>选择要推广的产品</label>
                    <select name="product_id" class="form-control" required>
                        <option value="">-- 请先选择产品 --</option>
                        <?php foreach ($allProducts as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['id']); ?>">
                                [<?php echo htmlspecialchars($p['tab_name']); ?>] <?php echo htmlspecialchars($p['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>选择绑定的代理通道</label>
                    <input type="text" class="form-control" placeholder="🔍 快捷搜索通道..." oninput="filterSelectOptions(this, 'addChannelSelect')" style="margin-bottom: 5px;">
                    <select name="channel_code" id="addChannelSelect" class="form-control" required size="4" style="height: 120px;">
                        <?php if (!empty($data['channels'])): foreach ($data['channels'] as $ch): ?>
                            <option value="<?php echo htmlspecialchars($ch['code']); ?>"><?php echo htmlspecialchars($ch['code'] . ' - ' . $ch['name']); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>该代理专属跳转域名</label>
                    <input type="text" name="channel_domain" class="form-control" placeholder="https://..." required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addMappingModal')">取消</button>
                    <button type="submit" class="btn btn-primary">💾 确定保存</button>
                </div>
            </form>
        </div>
    </div>

    <div id="channelMappingModal" class="modal">
        <div class="modal-content" style="max-width: 500px;"><div class="modal-header">🔗 分配其他代理跳转</div><div style="margin-bottom: 20px; padding: 10px; background: #f9fafb;">为 <strong><span id="mappingProductName" style="color:var(--primary-color);"></span></strong> 配置跳转：</div><form method="POST"><input type="hidden" name="action" value="set_channel_mapping"><input type="hidden" name="product_id" id="mappingProductId"><div class="form-group"><label>选择代理通道</label><input type="text" class="form-control" placeholder="🔍 快捷搜索通道..." oninput="filterSelectOptions(this, 'otherChannelSelect')" style="margin-bottom: 5px;"><select name="channel_code" id="otherChannelSelect" class="form-control" required size="4" style="height: 120px;"><?php if (!empty($data['channels'])): foreach ($data['channels'] as $ch): ?><option value="<?php echo htmlspecialchars($ch['code']); ?>"><?php echo htmlspecialchars($ch['code'] . ' - ' . $ch['name']); ?></option><?php endforeach; endif; ?></select></div><div class="form-group"><label>跳转域名</label><input type="text" name="channel_domain" class="form-control" placeholder="https://..." required></div><div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeModal('channelMappingModal')">取消</button><button type="submit" class="btn btn-primary">💾 确定</button></div></form></div>
    </div>

    <div id="batchMappingModal" class="modal">
        <div class="modal-content" style="max-width: 900px;"><div class="modal-header">📦 批量为代理商分配产品</div><form method="POST"><input type="hidden" name="action" value="batch_set_channel_mapping"><div class="form-group"><label>1. 选择目标代理通道</label><input type="text" class="form-control" placeholder="🔍 快捷搜索通道..." oninput="filterSelectOptions(this, 'batchChannelSelect')" style="margin-bottom: 5px;"><select name="channel_code" id="batchChannelSelect" class="form-control" required size="4" style="height: 120px;"><?php if (!empty($data['channels'])): foreach ($data['channels'] as $ch): ?><option value="<?php echo htmlspecialchars($ch['code']); ?>"><?php echo htmlspecialchars($ch['code'] . ' - ' . $ch['name']); ?></option><?php endforeach; endif; ?></select></div><div style="margin-top: 20px;"><label>2. 勾选允许该代理推广的产品，并填入跳转域名：</label><div style="height:300px; overflow-y:auto; border:1px solid #e5e7eb; padding:10px; margin-top:10px; border-radius:8px;"><div class="checkbox-grid"><?php foreach ($allProducts as $prod): ?><label class="checkbox-item" style="display:flex; flex-direction:column; align-items:flex-start; gap:5px;"><div style="display:flex; align-items:center; gap:5px; width:100%;"><input type="checkbox" name="product_ids[]" value="<?php echo htmlspecialchars($prod['id']); ?>"><strong><?php echo htmlspecialchars($prod['name']); ?></strong></div><input type="text" name="channel_domains[]" class="form-control" placeholder="输入跳转域名" style="font-size:12px; padding:6px;"></label><?php endforeach; ?></div></div></div><div class="modal-actions"><button type="button" class="btn btn-outline" onclick="closeModal('batchMappingModal')">取消</button><button type="submit" class="btn btn-primary">💾 批量应用配置</button></div></form></div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        document.querySelectorAll('.modal').forEach(modal => { modal.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; }); });

        // ✨ 高级防误触系统逻辑
        let currentDeleteForm = null;
        function confirmDelete(event, form, message) {
            event.preventDefault(); 
            currentDeleteForm = form;
            document.getElementById('deleteConfirmMessage').innerHTML = message;
            openModal('deleteConfirmModal');
        }
        function executeDelete() {
            if (currentDeleteForm) {
                currentDeleteForm.submit(); 
            }
        }

        function editProduct(tabId, index, name, url, iconUrl) {
            document.getElementById('editTabId').value = tabId; document.getElementById('editIndex').value = index;
            document.getElementById('editName').value = name; document.getElementById('editUrl').value = url;
            document.getElementById('editIconUrl').value = iconUrl; openModal('editModal');
        }
        function showProductMappingModal(productId, productName) {
            document.getElementById('mappingProductId').value = productId; document.getElementById('mappingProductName').innerText = productName; openModal('channelMappingModal');
        }
        function showBatchMappingModal() { openModal('batchMappingModal'); }
        
        function filterMappingTable() {
            const agentQuery = document.getElementById('searchAgent').value.toLowerCase();
            const domainQuery = document.getElementById('searchDomain').value.toLowerCase();
            const rows = document.querySelectorAll('#mappingTableBody .mapping-row');
            rows.forEach(row => {
                const agentCell = row.querySelector('.cell-agent').innerText.toLowerCase();
                const domainCell = row.querySelector('.cell-domain').innerText.toLowerCase();
                if (agentCell.includes(agentQuery) && domainCell.includes(domainQuery)) { row.style.display = ''; } else { row.style.display = 'none'; }
            });
        }

        function filterSelectOptions(input, selectId) {
            const term = input.value.toLowerCase();
            const select = document.getElementById(selectId);
            Array.from(select.options).forEach(opt => {
                if(opt.value === "") return;
                const text = opt.innerText.toLowerCase();
                opt.hidden = !text.includes(term);
            });
        }

        // ✨ 按分类筛选产品
        function filterProducts(tabId, btnElement) {
            const container = document.getElementById('productFilterContainer');
            container.querySelectorAll('button').forEach(btn => {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline');
            });
            btnElement.classList.remove('btn-outline');
            btnElement.classList.add('btn-primary');

            const rows = document.querySelectorAll('.product-row');
            rows.forEach(row => {
                if (tabId === 'all' || row.getAttribute('data-tab') === tabId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>

    <?php if ($activeModule === 'dashboard'): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx7 = document.getElementById('chart7Days').getContext('2d');
        new Chart(ctx7, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart7Dates); ?>,
                datasets: [
                    { label: '访问量 (PV)', data: <?php echo json_encode($chart7PV); ?>, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.1)', fill: true, tension: 0.3 },
                    { label: '独立访客 (UV)', data: <?php echo json_encode($chart7UV); ?>, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.3 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { tooltip: { mode: 'index', intersect: false }, legend: { position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });

        const ctx24 = document.getElementById('chart24Hours').getContext('2d');
        new Chart(ctx24, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart24Hours); ?>,
                datasets: [
                    { label: '今日每小时点击量 (PV)', data: <?php echo json_encode($chart24PV); ?>, backgroundColor: '#3b82f6', borderRadius: 4 },
                    { label: '今日每小时访客数 (UV)', data: <?php echo json_encode($chart24UV); ?>, backgroundColor: '#10b981', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { tooltip: { mode: 'index', intersect: false }, legend: { position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
