<?php
/**
 * 微信转账模拟系统 - 后端接口
 * 支持 MySQL 数据库和 JSON 文件双模式
 * 
 * 使用 MySQL 模式：
 * 1. 执行 init.sql 初始化数据库
 * 2. 修改下方 DB_* 常量
 * 
 * 使用 JSON 模式（无需数据库）：
 * DB_HOST 留空即可自动切换
 */

// ---- 配置 ----
define('DB_HOST', '');          // MySQL 主机，留空使用 JSON 模式
define('DB_PORT', '3306');       // MySQL 端口
define('DB_NAME', 'wxtransfer'); // 数据库名
define('DB_USER', 'root');      // 数据库用户名
define('DB_PASS', '');          // 数据库密码

// JSON 模式配置文件
define('DATA_FILE', __DIR__ . '/data.json');

// ---- 数据库连接 ----
$pdo = null;
if (DB_HOST) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    } catch (PDOException $e) {
        out_err('数据库连接失败: ' . $e->getMessage());
    }
}

// ---- 输出函数 ----
function out_ok($data = []) { echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE); exit; }
function out_err($msg) { echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

// ---- Session ----
if (session_status() === PHP_SESSION_NONE) {
    session_name('wxtransfer');
    session_start();
}

// ---- 获取请求参数 ----
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// 兼容 GET/POST
function req($key, $default = '') {
    global $method;
    if ($method === 'POST') {
        return $_POST[$key] ?? $default;
    }
    return $_GET[$key] ?? $default;
}

// ---- 登录验证 ----
function need_login() {
    if (empty($_SESSION['user_id'])) {
        out_err('请先登录');
    }
}

function need_admin() {
    need_login();
    if ($_SESSION['role'] !== 'admin') {
        out_err('需要管理员权限');
    }
}

// ========================================================
// 路由分发
// ========================================================
switch ($action) {

    // ---------- 登录 ----------
    case 'login': {
        $username = trim(req('username', ''));
        $password = req('password', '');
        
        if (empty($username) || empty($password)) {
            out_err('请输入用户名和密码');
        }
        
        if ($pdo) {
            // MySQL 模式
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($password, $user['password'])) {
                out_err('用户名或密码错误');
            }
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['nickname'] = $user['nickname'];
            
            out_ok(['user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'nickname' => $user['nickname']
            ]]);
        } else {
            // JSON 模式
            $data = json_decode(file_get_contents(DATA_FILE), true);
            foreach ($data['admins'] as $u) {
                if ($u['user'] === $username && $u['pass'] === $password) {
                    $_SESSION['user_id'] = 1;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = 'admin';
                    $_SESSION['nickname'] = '管理员';
                    out_ok(['user' => ['id' => 1, 'username' => $username, 'role' => 'admin', 'nickname' => '管理员']]);
                }
            }
            out_err('用户名或密码错误');
        }
        break;
    }
    
    // ---------- 登出 ----------
    case 'logout': {
        session_destroy();
        out_ok();
        break;
    }
    
    // ---------- 获取当前用户信息 ----------
    case 'me': {
        if (empty($_SESSION['user_id'])) {
            out_ok(['user' => null]);
        }
        out_ok(['user' => [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'role' => $_SESSION['role'] ?? 'member',
            'nickname' => $_SESSION['nickname'] ?? ''
        ]]);
        break;
    }

    // ---------- 修改密码 ----------
    case 'change_password': {
        need_login();
        $old_pass = req('old_password', '');
        $new_pass = req('new_password', '');
        
        if (empty($old_pass) || empty($new_pass)) {
            out_err('请填写完整');
        }
        
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!password_verify($old_pass, $user['password'])) {
                out_err('原密码错误');
            }
            
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $_SESSION['user_id']]);
            out_ok();
        } else {
            out_err('JSON模式不支持修改密码');
        }
        break;
    }

    // ========================================================
    // 管理员功能
    // ========================================================
    
    // ---------- 会员列表 ----------
    case 'member_list': {
        need_admin();
        
        if ($pdo) {
            $stmt = $pdo->query("SELECT id, username, nickname, role, status, created_at FROM users WHERE role = 'member' ORDER BY id DESC");
            $members = $stmt->fetchAll();
            
            // 统计每个会员的转账数量
            foreach ($members as &$m) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as cnt, SUM(received=1) as received_cnt FROM transfers WHERE user_id = ?");
                $stmt->execute([$m['id']]);
                $stats = $stmt->fetch();
                $m['transfer_count'] = $stats['cnt'] ?? 0;
                $m['received_count'] = $stats['received_cnt'] ?? 0;
            }
            
            out_ok(['members' => $members]);
        } else {
            out_ok(['members' => []]);
        }
        break;
    }
    
    // ---------- 添加会员 ----------
    case 'member_add': {
        need_admin();
        $username = trim(req('username', ''));
        $password = req('password', '');
        $nickname = trim(req('nickname', ''));
        
        if (empty($username) || empty($password)) {
            out_err('用户名和密码不能为空');
        }
        
        if ($pdo) {
            // 检查用户名是否存在
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                out_err('用户名已存在');
            }
            
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, nickname, role) VALUES (?, ?, ?, 'member')");
            $stmt->execute([$username, $hash, $nickname ?: $username]);
            
            out_ok(['id' => $pdo->lastInsertId()]);
        } else {
            out_err('JSON模式不支持添加会员');
        }
        break;
    }
    
    // ---------- 编辑会员 ----------
    case 'member_edit': {
        need_admin();
        $id = intval(req('id', 0));
        $nickname = trim(req('nickname', ''));
        $status = intval(req('status', 1));
        $password = req('password', '');
        
        if ($id <= 0) out_err('参数错误');
        
        if ($pdo) {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET nickname = ?, status = ?, password = ? WHERE id = ? AND role = 'member'");
                $stmt->execute([$nickname, $status, $hash, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET nickname = ?, status = ? WHERE id = ? AND role = 'member'");
                $stmt->execute([$nickname, $status, $id]);
            }
            out_ok();
        } else {
            out_err('JSON模式不支持编辑会员');
        }
        break;
    }
    
    // ---------- 删除会员 ----------
    case 'member_delete': {
        need_admin();
        $id = intval(req('id', 0));
        
        if ($id <= 0) out_err('参数错误');
        
        if ($pdo) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'member'");
            $stmt->execute([$id]);
            out_ok();
        } else {
            out_err('JSON模式不支持删除会员');
        }
        break;
    }

    // ========================================================
    // 转账记录
    // ========================================================
    
    // ---------- 转账列表 ----------
    case 'list': {
        need_login();
        
        $user_id = $_SESSION['role'] === 'admin' ? intval(req('user_id', 0)) : $_SESSION['user_id'];
        
        if ($pdo) {
            if ($user_id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM transfers WHERE user_id = ? ORDER BY id DESC");
                $stmt->execute([$user_id]);
            } else {
                $stmt = $pdo->query("SELECT t.*, u.username, u.nickname FROM transfers t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.id DESC");
            }
            $items = $stmt->fetchAll();
            out_ok(['items' => $items]);
        } else {
            $data = json_decode(file_get_contents(DATA_FILE), true);
            out_ok(['items' => $data['items'] ?? []]);
        }
        break;
    }
    
    // ---------- 单条查询 ----------
    case 'get': {
        $id = intval(req('id', 0));
        
        if ($id <= 0) out_err('参数错误');
        
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT t.*, u.username, u.nickname FROM transfers t LEFT JOIN users u ON t.user_id = u.id WHERE t.id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            
            if (!$item) out_err('记录不存在');
            out_ok(['item' => $item]);
        } else {
            $data = json_decode(file_get_contents(DATA_FILE), true);
            foreach ($data['items'] ?? [] as $it) {
                if (intval($it['id']) === $id) {
                    out_ok(['item' => $it]);
                }
            }
            out_err('记录不存在');
        }
        break;
    }
    
    // ---------- 添加转账 ----------
    case 'add': {
        need_login();
        
        $title = trim(req('title', ''));
        $description = trim(req('description', ''));
        $time = trim(req('time', date('Y-m-d H:i:s')));
        $transfer_type = trim(req('transfer_type', '微信商家转账'));
        $remark = trim(req('remark', ''));
        $pay_method = trim(req('pay_method', '零钱'));
        
        if (empty($title)) out_err('标题不能为空');
        
        if ($pdo) {
            $stmt = $pdo->prepare("INSERT INTO transfers (user_id, title, description, time, transfer_type, remark, pay_method) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                $title,
                $description,
                $time,
                $transfer_type,
                $remark,
                $pay_method
            ]);
            $id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ?");
            $stmt->execute([$id]);
            out_ok(['item' => $stmt->fetch()]);
        } else {
            // JSON 模式
            $data = json_decode(file_get_contents(DATA_FILE), true);
            $item = [
                'id' => time(),
                'user_id' => $_SESSION['user_id'],
                'title' => $title,
                'description' => $description,
                'time' => $time,
                'transfer_type' => $transfer_type,
                'remark' => $remark,
                'pay_method' => $pay_method,
                'received' => false,
                'received_time' => '',
                'created_at' => date('Y-m-d H:i:s')
            ];
            $data['items'][] = $item;
            file_put_contents(DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            out_ok(['item' => $item]);
        }
        break;
    }
    
    // ---------- 更新转账 ----------
    case 'update': {
        need_login();
        
        $id = intval(req('id', 0));
        if ($id <= 0) out_err('参数错误');
        
        $title = trim(req('title', ''));
        $description = trim(req('description', ''));
        $time = trim(req('time', ''));
        $transfer_type = trim(req('transfer_type', ''));
        $remark = trim(req('remark', ''));
        $pay_method = trim(req('pay_method', ''));
        
        if ($pdo) {
            // 权限检查：管理员可编辑所有，会员只能编辑自己的
            $stmt = $pdo->prepare("SELECT user_id FROM transfers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            
            if (!$row) out_err('记录不存在');
            if ($_SESSION['role'] !== 'admin' && $row['user_id'] != $_SESSION['user_id']) {
                out_err('无权操作');
            }
            
            $sql = "UPDATE transfers SET title=?, description=?, time=?, transfer_type=?, remark=?, pay_method=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$title, $description, $time, $transfer_type, $remark, $pay_method, $id]);
            
            $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ?");
            $stmt->execute([$id]);
            out_ok(['item' => $stmt->fetch()]);
        } else {
            $data = json_decode(file_get_contents(DATA_FILE), true);
            foreach ($data['items'] as &$it) {
                if (intval($it['id']) === $id) {
                    $it['title'] = $title;
                    $it['description'] = $description;
                    $it['time'] = $time;
                    $it['transfer_type'] = $transfer_type;
                    $it['remark'] = $remark;
                    $it['pay_method'] = $pay_method;
                    file_put_contents(DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    out_ok(['item' => $it]);
                }
            }
            out_err('记录不存在');
        }
        break;
    }
    
    // ---------- 删除转账 ----------
    case 'delete': {
        need_login();
        
        $id = intval(req('id', 0));
        if ($id <= 0) out_err('参数错误');
        
        if ($pdo) {
            // 权限检查
            $stmt = $pdo->prepare("SELECT user_id FROM transfers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            
            if (!$row) out_err('记录不存在');
            if ($_SESSION['role'] !== 'admin' && $row['user_id'] != $_SESSION['user_id']) {
                out_err('无权操作');
            }
            
            $stmt = $pdo->prepare("DELETE FROM transfers WHERE id = ?");
            $stmt->execute([$id]);
            out_ok();
        } else {
            $data = json_decode(file_get_contents(DATA_FILE), true);
            $data['items'] = array_values(array_filter($data['items'], function($it) use ($id) {
                return intval($it['id']) !== $id;
            }));
            file_put_contents(DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            out_ok();
        }
        break;
    }
    
    // ---------- 确认收款 ----------
    case 'confirm': {
        $id = intval(req('id', 0));
        if ($id <= 0) out_err('参数错误');
        
        $received_time = trim(req('received_time', date('Y-m-d H:i:s')));
        
        if ($pdo) {
            $stmt = $pdo->prepare("UPDATE transfers SET received=1, received_time=? WHERE id=?");
            $stmt->execute([$received_time, $id]);
            
            $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ?");
            $stmt->execute([$id]);
            out_ok(['item' => $stmt->fetch()]);
        } else {
            $data = json_decode(file_get_contents(DATA_FILE), true);
            foreach ($data['items'] as &$it) {
                if (intval($it['id']) === $id) {
                    $it['received'] = true;
                    $it['received_time'] = $received_time;
                    file_put_contents(DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    out_ok(['item' => $it]);
                }
            }
            out_err('记录不存在');
        }
        break;
    }

    // ---------- 统计 ----------
    case 'stats': {
        need_login();
        
        if ($pdo) {
            if ($_SESSION['role'] === 'admin') {
                $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(received=1) as received FROM transfers");
                $transfer_stats = $stmt->fetch();
                
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'member'");
                $member_count = $stmt->fetch();
                
                out_ok([
                    'total' => $transfer_stats['total'] ?? 0,
                    'received' => $transfer_stats['received'] ?? 0,
                    'member_count' => $member_count['total'] ?? 0
                ]);
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(received=1) as received FROM transfers WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $stats = $stmt->fetch();
                out_ok([
                    'total' => $stats['total'] ?? 0,
                    'received' => $stats['received'] ?? 0
                ]);
            }
        } else {
            $data = json_decode(file_get_contents(DATA_FILE), true);
            $items = $data['items'] ?? [];
            $received = count(array_filter($items, function($it) { return !empty($it['received']); }));
            out_ok(['total' => count($items), 'received' => $received]);
        }
        break;
    }

    default:
        out_err('未知操作');
}
