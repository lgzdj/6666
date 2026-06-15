<?php
// 微信转账模拟 - 后端接口（PHP + JSON 存储，无需数据库）
// 使用方式：
//   1) 在项目目录执行: php -S 0.0.0.0:8080
//   2) 浏览器打开: http://127.0.0.1:8080/index.html
//   3) 默认账号: admin / 密码: admin123  (可在 data.json 的 admins 中修改)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// ---- 配置 ----
define('DATA_FILE', __DIR__ . '/data.json');
define('DEFAULT_ADMIN', ['user' => 'admin', 'pass' => 'admin123']);

// ---- 初始化数据文件 ----
if (!file_exists(DATA_FILE)) {
    $init = [
        'admins' => [DEFAULT_ADMIN],
        'items'  => []
    ];
    $result = file_put_contents(DATA_FILE, json_encode($init, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($result === false) {
        die(json_encode(['ok' => false, 'error' => '无法创建数据文件，请检查目录权限']));
    }
    // Windows不支持chmod，跳过
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        @chmod(DATA_FILE, 0666);
    }
}

// ---- 读/写 ----
function read_data() {
    $raw = file_get_contents(DATA_FILE);
    if (!$raw) return ['admins' => [['user' => 'admin', 'pass' => 'admin123']], 'items' => []];
    return json_decode($raw, true) ?: ['admins' => [['user' => 'admin', 'pass' => 'admin123']], 'items' => []];
}

function write_data($data) {
    $result = file_put_contents(DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($result === false) {
        die(json_encode(['ok' => false, 'error' => '无法写入数据文件，请检查 data.json 权限']));
    }
}

// ---- Session ----
if (session_status() === PHP_SESSION_NONE) {
    session_name('wxtransfer');
    session_start();
}

// ---- 路由 ----
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$data   = read_data();

switch ($action) {

    // ---------- 登录 ----------
    case 'login': {
        $user = trim($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        if ($user === '' || $pass === '') { out_err('账号密码不能为空'); }
        foreach ($data['admins'] as $a) {
            if ($a['user'] === $user && $a['pass'] === $pass) {
                $_SESSION['ok'] = true;
                $_SESSION['user'] = $user;
                out_ok(['user' => $user]);
            }
        }
        out_err('账号或密码错误');
        break;
    }

    // ---------- 登出 ----------
    case 'logout': {
        $_SESSION = [];
        session_destroy();
        out_ok([]);
        break;
    }

    // ---------- 状态 ----------
    case 'me': {
        out_ok(['ok' => !empty($_SESSION['ok']), 'user' => $_SESSION['user'] ?? '']);
        break;
    }

    // ---------- 列表 ----------
    case 'list': {
        need_login();
        $items = array_values($data['items']);
        usort($items, function($a, $b) { return ($b['id'] ?? 0) - ($a['id'] ?? 0); });
        out_ok(['items' => $items]);
        break;
    }

    // ---------- 单条查询（分享页/转账页用） ----------
    case 'get': {
        $id = intval($_GET['id'] ?? ($_POST['id'] ?? 0));
        $found = null;
        foreach ($data['items'] as $it) {
            if (intval($it['id']) === $id) { $found = $it; break; }
        }
        if (!$found) out_err('记录不存在');
        out_ok(['item' => $found]);
        break;
    }

    // ---------- 新增 ----------
    case 'add': {
        need_login();
        $item = parse_item();
        $item['id'] = time();
        if (empty($item['created_at'])) {
            $item['created_at'] = date('Y-m-d H:i:s');
        }
        $data['items'][] = $item;
        write_data($data);
        out_ok(['item' => $item]);
        break;
    }

    // ---------- 修改 ----------
    case 'update': {
        need_login();
        $id = intval($_POST['id'] ?? 0);
        foreach ($data['items'] as &$it) {
            if (intval($it['id']) === $id) {
                $patch = parse_item();
                // 保留原 id / created_at
                $patch['id'] = $id;
                if (empty($patch['created_at'])) {
                    $patch['created_at'] = $it['created_at'] ?? date('Y-m-d H:i:s');
                }
                $it = $patch;
                write_data($data);
                out_ok(['item' => $it]);
            }
        }
        out_err('记录不存在');
        break;
    }

    // ---------- 删除 ----------
    case 'delete': {
        need_login();
        $id = intval($_POST['id'] ?? 0);
        $data['items'] = array_values(array_filter($data['items'], function($it) use ($id) {
            return intval($it['id']) !== $id;
        }));
        write_data($data);
        out_ok([]);
        break;
    }

    // ---------- 模拟点击"确认收款"（分享/转账页用） ----------
    case 'confirm': {
        $id = intval($_POST['id'] ?? 0);
        $found = null;
        foreach ($data['items'] as &$it) {
            if (intval($it['id']) === $id) {
                $it['received'] = true;
                $it['received_time'] = !empty($_POST['received_time'])
                    ? $_POST['received_time']
                    : date('Y-m-d H:i:s');
                $found = &$it;
                break;
            }
        }
        if (!$found) out_err('记录不存在');
        write_data($data);
        out_ok(['item' => $found]);
        break;
    }

    default: out_err('未知 action: ' . $action);
}

// ---------- 工具函数 ----------
function need_login() {
    if (empty($_SESSION['ok'])) { out_err('未登录', 401); }
}
function out_ok($payload) {
    echo json_encode(array_merge(['ok' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}
function out_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function parse_item() {
    return [
        'title'         => trim($_POST['title'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
        'time'          => trim($_POST['time'] ?? ''),
        'received'      => !empty($_POST['received']),
        'received_time' => trim($_POST['received_time'] ?? ''),
        'transfer_type' => trim($_POST['transfer_type'] ?? '微信商家转账'),
        'remark'        => trim($_POST['remark'] ?? ''),
        'pay_method'    => trim($_POST['pay_method'] ?? '零钱'),
    ];
}
