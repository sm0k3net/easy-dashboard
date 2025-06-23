<?php
session_start();

// --- CONFIGURATION ---
$possible_dirs = [
    __DIR__ . '/data/',
    __DIR__ . '/',
    sys_get_temp_dir() . '/dashboard_data/',
    '/tmp/dashboard_data/'
];
$data_dir = null;
foreach ($possible_dirs as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_dir($dir) && is_writable($dir)) {
        $data_dir = $dir;
        break;
    }
}
define('CONFIG_FILE', $data_dir . 'dashboard_config.json');
define('USERS_FILE', $data_dir . 'users.json');
define('ICONS_DIR', __DIR__ . '/icons/');
if (!is_dir(ICONS_DIR)) @mkdir(ICONS_DIR, 0755, true);

// --- INIT CONFIG ---
function initializeConfig() {
    $defaultConfig = [
        'dashboard_title' => 'Dashboard',
        'groups' => [
            [
                'id' => 1,
                'name' => 'Data Management',
                'color' => '#f5f5f7',
                'services' => [
                    [
                        'id' => 1,
                        'title' => 'Slack',
                        'description' => 'Team communication',
                        'url' => 'https://slack.com',
                        'icon_type' => 'fa',
                        'icon' => 'fa-brands fa-slack',
                        'shared' => false,
                        'allowed_users' => [],
                        'note' => ''
                    ],
                    [
                        'id' => 2,
                        'title' => 'Asana',
                        'description' => 'Task tracking',
                        'url' => 'https://asana.com',
                        'icon_type' => 'fa',
                        'icon' => 'fa-brands fa-asana',
                        'shared' => false,
                        'allowed_users' => [],
                        'note' => ''
                    ]
                ]
            ]
        ],
        'next_group_id' => 2,
        'next_service_id' => 3
    ];
    if (!file_exists(CONFIG_FILE)) {
        file_put_contents(CONFIG_FILE, json_encode($defaultConfig, JSON_PRETTY_PRINT));
    }
    $defaultUsers = [
        'admin' => [
            'password' => password_hash('admin123', PASSWORD_DEFAULT),
            'role' => 'admin'
        ]
    ];
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode($defaultUsers, JSON_PRETTY_PRINT));
    }
}
function loadConfig() {
    if (!file_exists(CONFIG_FILE)) initializeConfig();
    return json_decode(file_get_contents(CONFIG_FILE), true);
}
function saveConfig($config) {
    file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT));
}
function loadUsers() {
    if (!file_exists(USERS_FILE)) initializeConfig();
    return json_decode(file_get_contents(USERS_FILE), true);
}
function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
}
function isAuthenticated() { return isset($_SESSION['user']); }
function isAdmin() { return isset($_SESSION['user']) && $_SESSION['role'] === 'admin'; }

// --- LOGIN/LOGOUT ---
if (isset($_POST['login'])) {
    $users = loadUsers();
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
        $_SESSION['user'] = $username;
        $_SESSION['role'] = $users[$username]['role'];
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    } else {
        $loginError = 'Invalid credentials';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// --- AJAX ACTIONS ---
if (isset($_POST['action']) && isAuthenticated()) {
    $config = loadConfig();
    $users = loadUsers();
    $response = ['success' => false];
    switch ($_POST['action']) {
        case 'add_group':
            if (isAdmin()) {
                $color = $_POST['color'] ?? '#f5f5f7';
                $config['groups'][] = [
                    'id' => $config['next_group_id'],
                    'name' => $_POST['name'],
                    'color' => $color,
                    'services' => []
                ];
                $config['next_group_id']++;
                saveConfig($config);
                $response = ['success' => true];
            }
            break;
        case 'update_group':
            if (isAdmin()) {
                foreach ($config['groups'] as &$group) {
                    if ($group['id'] == $_POST['id']) {
                        $group['name'] = $_POST['name'];
                        $group['color'] = $_POST['color'] ?? '#f5f5f7';
                        break;
                    }
                }
                saveConfig($config);
                $response = ['success' => true];
            }
            break;
        case 'delete_group':
            if (isAdmin()) {
                $config['groups'] = array_values(array_filter($config['groups'], fn($g) => $g['id'] != $_POST['id']));
                saveConfig($config);
                $response = ['success' => true];
            }
            break;
        case 'add_service':
        case 'update_service':
            if (isAdmin()) {
                $icon_type = $_POST['icon_type'];
                $icon = '';
                if ($icon_type === 'fa') $icon = $_POST['icon_fa'];
                elseif ($icon_type === 'emoji') $icon = $_POST['icon_emoji'];
                elseif ($icon_type === 'url') $icon = $_POST['icon_url'];
                elseif ($icon_type === 'upload') {
                    if (isset($_FILES['icon_file']) && $_FILES['icon_file']['tmp_name']) {
                        $fname = uniqid('icon_') . '.png';
                        $target = ICONS_DIR . $fname;
                        $img = imagecreatefromstring(file_get_contents($_FILES['icon_file']['tmp_name']));
                        $dst = imagecreatetruecolor(46, 46);
                        imagealphablending($dst, false); imagesavealpha($dst, true);
                        imagecopyresampled($dst, $img, 0, 0, 0, 0, 46, 46, imagesx($img), imagesy($img));
                        imagepng($dst, $target);
                        imagedestroy($img); imagedestroy($dst);
                        $icon = 'icons/' . $fname;
                    } else {
                        $icon = $_POST['icon_upload_existing'] ?? '';
                    }
                }
                $service = [
                    'title' => $_POST['title'],
                    'description' => $_POST['description'],
                    'url' => $_POST['url'],
                    'icon_type' => $icon_type,
                    'icon' => $icon,
                    'shared' => isset($_POST['shared']) && ($_POST['shared'] === 'true' || $_POST['shared'] === 'on'),
                    'allowed_users' => isset($_POST['allowed_users']) ? (array)$_POST['allowed_users'] : [],
                    'note' => $_POST['note'] ?? ''
                ];
                if ($_POST['action'] === 'add_service') {
                    foreach ($config['groups'] as &$group) {
                        if ($group['id'] == $_POST['group_id']) {
                            $service['id'] = $config['next_service_id'];
                            $group['services'][] = $service;
                            $config['next_service_id']++;
                            break;
                        }
                    }
                } else { // update_service
                    foreach ($config['groups'] as &$group) {
                        foreach ($group['services'] as &$srv) {
                            if ($srv['id'] == $_POST['id']) {
                                if ($icon_type === 'upload' && empty($icon)) {
                                    $service['icon'] = $srv['icon'];
                                }
                                $srv = array_merge($srv, $service);
                                break 2;
                            }
                        }
                    }
                }
                saveConfig($config);
                $response = ['success' => true];
            }
            break;
        case 'delete_service':
            if (isAdmin()) {
                foreach ($config['groups'] as &$group) {
                    $group['services'] = array_values(array_filter($group['services'], fn($s) => $s['id'] != $_POST['id']));
                }
                saveConfig($config);
                $response = ['success' => true];
            }
            break;
        case 'create_guest':
            if (isAdmin()) {
                $guestUsername = trim($_POST['username']);
                $guestPassword = $_POST['password'];
                if ($guestUsername === '' || $guestPassword === '') {
                    $response = ['success' => false, 'message' => 'Username and password required'];
                    break;
                }
                if (!isset($users[$guestUsername])) {
                    $users[$guestUsername] = [
                        'password' => password_hash($guestPassword, PASSWORD_DEFAULT),
                        'role' => 'guest'
                    ];
                    saveUsers($users);
                    $response = ['success' => true, 'username' => $guestUsername];
                } else {
                    $response = ['success' => false, 'message' => 'Username already exists'];
                }
            }
            break;
        case 'delete_guest':
            if (isAdmin()) {
                $username = $_POST['username'];
                if (isset($users[$username]) && $users[$username]['role'] === 'guest') {
                    unset($users[$username]);
                    saveUsers($users);
                    $response = ['success' => true];
                } else {
                    $response = ['success' => false, 'message' => 'User not found'];
                }
            }
            break;
        case 'export_config':
            if (isAdmin()) $response = ['success' => true, 'data' => $config];
            break;
        case 'import_config':
            if (isAdmin()) {
                $importData = json_decode($_POST['config_data'], true);
                if ($importData) {
                    saveConfig($importData);
                    $response = ['success' => true];
                } else {
                    $response = ['success' => false, 'message' => 'Invalid JSON data'];
                }
            }
            break;
        case 'get_note':
            $serviceId = $_POST['id'];
            $note = '';
            foreach ($config['groups'] as $group)
                foreach ($group['services'] as $service)
                    if ($service['id'] == $serviceId) { $note = $service['note'] ?? ''; break 2; }
            $response = ['success' => true, 'note' => $note];
            break;
        case 'set_note':
            if (isAdmin()) {
                $serviceId = $_POST['id'];
                $note = $_POST['note'] ?? '';
                foreach ($config['groups'] as &$group)
                    foreach ($group['services'] as &$service)
                        if ($service['id'] == $serviceId) { $service['note'] = $note; break 2; }
                saveConfig($config);
                $response = ['success' => true];
            }
            break;
        case 'update_dashboard_title':
            if (isAdmin()) {
                $config['dashboard_title'] = $_POST['title'];
                saveConfig($config);
                $response = ['success' => true];
            }
            break;
        case 'change_password':
            if (isAuthenticated()) {
                $username = $_SESSION['user'];
                $old = $_POST['old_password'] ?? '';
                $new = $_POST['new_password'] ?? '';
                $users = loadUsers();
                if (!isset($users[$username]) || !password_verify($old, $users[$username]['password'])) {
                    $response = ['success' => false, 'message' => 'Current password is incorrect'];
                } elseif (strlen($new) < 4) {
                    $response = ['success' => false, 'message' => 'New password too short'];
                } else {
                    $users[$username]['password'] = password_hash($new, PASSWORD_DEFAULT);
                    saveUsers($users);
                    $response = ['success' => true];
                }
            }
            break;
    }
    header('Content-Type: application/json');
    echo json_encode($response); exit;
}

// --- INIT ---
initializeConfig();
$config = loadConfig();

// --- Гостевой режим: фильтрация сервисов без дублей и без ошибок! ---
if (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') {
    $currentUser = $_SESSION['user'];
    $filtered_groups = [];
    $shown_service_ids = []; // Глобальный массив для всех групп

    foreach ($config['groups'] as $group) {
        $unique_services = [];
        foreach ($group['services'] as $service) {
            $show = (isset($service['shared']) && $service['shared']) ||
                    (isset($service['allowed_users']) && in_array($currentUser, (array)$service['allowed_users']));
            // Показываем только если сервис ещё не был показан ни в одной группе
            if ($show && !in_array($service['id'], $shown_service_ids)) {
                $unique_services[] = $service;
                $shown_service_ids[] = $service['id'];
            }
        }
        // Гости не видят allowed_users других сервисов
        foreach ($unique_services as &$service) {
            unset($service['allowed_users']);
        }
        unset($service);
        if (!empty($unique_services)) {
            $group['services'] = $unique_services;
            $filtered_groups[] = $group;
        }
    }
    $config['groups'] = $filtered_groups;
}

// --- PALETTE ---
$palette = [
    "#f5f5f7", "#e3fcec", "#e7f0fd", "#fff4e6", "#fce4ec", "#f3e8ff", "#e0f7fa", "#f9fbe7", "#f1f8e9", "#fffde7",
    "#e3e8fc", "#fce3ec", "#fff4e3", "#e3f6fc", "#f3e3fc", "#fceee3", "#e0e0e0", "#e6ffe6", "#e6f7ff", "#ffe6fa"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($config['dashboard_title'] ?? 'Dashboard') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        html, body { height: 100%; }
        body { font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background: #f5f5f7; margin: 0; min-height: 100vh; }
        .login-container { display:flex;justify-content:center;align-items:center;min-height:100vh;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);}
        .login-form { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.1); width:100%;max-width:400px; }
        .login-form h2 { text-align:center;margin-bottom:1.5rem;color:#333;}
        .form-group { margin-bottom:1rem;}
        label { display:block;margin-bottom:0.5rem;color:#555;font-weight:500;}
        input[type="text"],input[type="password"],input[type="url"],textarea,select {
            width:100%;padding:0.75rem;border:2px solid #e1e5e9;border-radius:8px;font-size:1rem;box-sizing:border-box;
        }
        button { background:#007aff;color:white;border:none;padding:0.75rem 1.5rem;border-radius:8px;font-size:1rem;cursor:pointer;}
        button:hover { background:#0056b3;}
        .btn-secondary { background:#6c757d;}
        .btn-danger { background:#dc3545;}
        .btn-success { background:#28a745;}
        .header {
            background:white;
            padding:1rem 2rem;
            box-shadow:0 2px 8px rgba(0,0,0,0.1);
            display:flex;
            justify-content:space-between;
            align-items:center;
            position:relative;
            z-index: 100;
            transition: margin-left 0.3s;
        }
        .header h1 { color:#333;margin-left:0;display:flex;align-items:center;gap:0.5rem;}
        .header h1 input { font-size:1.5rem;font-weight:600;border:none;background:transparent;color:#333;width:auto;min-width:120px;outline:none;border-bottom:1px dashed #ccc;}
        .header h1 input:focus { border-bottom:2px solid #007aff;}
        .user-info { display:flex;align-items:center;gap:1rem;}
        .main-content {
            max-width:1200px;
            margin:2rem auto;
            padding:0 2rem;
            transition: margin-left 0.3s;
        }
        .group { border-radius:12px;padding:1.5rem;margin-bottom:2rem;box-shadow:0 2px 8px rgba(0,0,0,0.1);background:var(--group-bg,#f5f5f7);}
        .group-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid #f0f0f0;}
        .group-title { font-size:1.5rem;font-weight:600;color:#333;}
        .group-actions { position:relative;}
        .group-actions .dropdown-toggle { background:#f1f3f6;color:#333;border:none;border-radius:6px;padding:0.5rem 1.2rem;font-size:1.1rem;cursor:pointer;}
        .group-actions .dropdown-menu { display:none;position:absolute;right:0;top:110%;background:#fff;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.13);min-width:180px;z-index:10;padding:0.5rem 0;}
        .group-actions .dropdown-menu.show { display:block;}
        .group-actions .dropdown-menu button { width:100%;background:none;border:none;color:#333;text-align:left;padding:0.7rem 1.2rem;font-size:1rem;cursor:pointer;}
        .group-actions .dropdown-menu button:hover { background:#f1f3f6;}
        .services-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;}
        .service-card { background:#f8f9fa;border-radius:8px;padding:1rem;border:2px solid transparent;transition:all 0.3s;cursor:pointer;position:relative;min-height:120px;display:flex;flex-direction:column;justify-content:flex-start;}
        .service-card:hover { border-color:#007aff;transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,0.1);}
        .service-icon-wrap { width:64px;height:64px;border-radius:16px;background:#f3f3f3;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.06);margin-bottom:0.5rem;}
        .service-icon-img { width:46px;height:46px;object-fit:contain;border-radius:8px;}
        .service-icon { font-size:2.7rem;min-height:2.7rem;display:block;}
        .service-icon .fa { font-size:2.7rem;}
        .service-title { font-weight:600;color:#333;margin-bottom:0.25rem;}
        .service-description { color:#666;font-size:0.9rem;margin-bottom:0.5rem;}
        .service-actions { position:absolute;top:0.5rem;right:0.5rem;display:flex;gap:0.25rem;opacity:0;transition:opacity 0.3s;}
        .service-card:hover .service-actions { opacity:1;}
        .service-actions button { padding:0.25rem 0.5rem;font-size:0.8rem;}
        .shared-badge { display:inline-block;background:#28a745;color:white;padding:0.25rem 0.5rem;border-radius:4px;font-size:0.7rem;font-weight:500;margin-right:0.5rem;margin-bottom:0.25rem;}
        .allowed-badge { display:inline-block;background:#007aff;color:white;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.7rem;font-weight:500;margin-right:0.25rem;margin-bottom:0.25rem;}
        .badges-row { margin-top:0.25rem;}
        .modal { display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background-color:rgba(0,0,0,0.5);}
        .modal-content { background-color:white;margin:5% auto;padding:2rem;border-radius:12px;width:90%;max-width:500px;position:relative;max-height:90vh;overflow-y:auto;box-sizing:border-box;}
        @media (max-width:600px) {
            .modal-content { max-width:98vw;padding:1rem;}
        }
        .close { position:absolute;right:1rem;top:1rem;font-size:1.5rem;cursor:pointer;color:#666;}
        .close:hover { color:#333;}
        .error { color:#dc3545;background:#f8d7da;border:1px solid #f5c6cb;padding:0.75rem;border-radius:4px;margin-bottom:1rem;}
        .success { color:#155724;background:#d4edda;border:1px solid #c3e6cb;padding:0.75rem;border-radius:4px;margin-bottom:1rem;}
        .checkbox-group { display:flex;align-items:center;gap:0.5rem;margin-top:0.5rem;}
        input[type="checkbox"] { width:auto;}
        /* Sidebar styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: #fff;
            box-shadow: 2px 0 8px rgba(0,0,0,0.08);
            transform: translateX(-100%);
            transition: transform 0.3s;
            z-index: 2000;
            padding: 2rem 1rem 1rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            overflow-y: auto;
        }
        .sidebar.open { transform: translateX(0); }
        .sidebar-toggle {
            position: fixed;
            left: 32px;
            top: 32px;
            z-index: 2100;
            background: #fff;
            color: #007aff;
            border: none;
            border-radius: 50%;
            width: 48px;
            height: 48px;
            font-size: 2rem;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s, color 0.2s;
        }
        .sidebar-toggle:hover {
            background: #007aff;
            color: #fff;
        }
        .sidebar-pin {
            background: #eee;
            border: none;
            border-radius: 6px;
            padding: 0.7rem 1rem;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            font-weight: 500;
            position: sticky;
            bottom: 1rem;
            margin-top: 2rem;
            z-index: 10;
        }
        .sidebar-pin:focus { outline: 2px solid #007aff; }
        body.sidebar-pinned .sidebar { transform: translateX(0); }
        body.sidebar-pinned .sidebar-toggle { display: none; }
        .sidebar button, .sidebar .sidebar-pin {
            width: 100%;
            margin-bottom: 0.5rem;
            font-size: 1rem;
            font-weight: 500;
        }
        .sidebar hr { margin: 1rem 0; }
        .sidebar h3 { margin-bottom: 1rem; }
        .sidebar h4 { margin-bottom: 0.5rem; }
        .sidebar ul { list-style: none; padding-left: 0; margin: 0; }
        .sidebar ul li {
            margin-bottom: 1rem;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            background: #f7f7f7;
            border-radius: 6px;
            padding: 0.5rem 0.7rem;
        }
        .sidebar .guest-name {
            font-weight: 500;
            margin-bottom: 0.4rem;
            word-break: break-all;
        }
        .sidebar .guest-delete-btn {
            background: #dc3545;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 0.3rem 1.2rem;
            font-size: 0.95rem;
            cursor: pointer;
            width: 100%;
            margin: 0;
        }
        .sidebar .guest-delete-btn:hover { background: #b71c1c; }
        @media (max-width: 900px) {
            .sidebar { width: 80vw; min-width: 180px; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 0 1rem; }
            .services-grid { grid-template-columns: 1fr; }
            .group-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .sidebar { width: 90vw; }
            .header h1 { margin-left: 0; }
        }
        @media (max-width: 600px) {
            .sidebar { width: 100vw; min-width: 0; padding: 1rem 0.5rem; }
        }
        body.sidebar-pinned .header,
        body.sidebar-pinned .main-content {
            margin-left: 290px;
        }
        @media (max-width: 900px) {
            body.sidebar-pinned .header,
            body.sidebar-pinned .main-content {
                margin-left: 82vw;
            }
        }
        @media (max-width: 600px) {
            body.sidebar-pinned .header,
            body.sidebar-pinned .main-content {
                margin-left: 0;
            }
        }
        #noteModal textarea { width:100%;min-height:80px;border-radius:8px;border:2px solid #e1e5e9;padding:0.75rem;font-size:1rem;margin-bottom:1rem;}
        #noteModal .modal-content { max-width:400px;}
        .note-btn { background:#ffe066;color:#333;border:none;border-radius:6px;padding:0.25rem 0.7rem;font-size:1rem;margin-right:0.3rem;cursor:pointer;}
        .note-btn:hover { background:#ffd43b;}
        .footer {
            margin-top: 3rem;
            padding: 1.5rem 0;
            background: #f1f3f6;
            color: #555;
            text-align: center;
            font-size: 1rem;
            border-top: 1px solid #e0e0e0;
        }
        .footer a { color: #007aff; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<?php if (!isAuthenticated()): ?>
    <div class="login-container">
        <form class="login-form" method="POST" autocomplete="off">
            <h2>Login to Dashboard</h2>
            <?php if (isset($loginError)): ?>
                <div class="error"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" name="login" style="width:100%;">Login</button>
        </form>
    </div>
<?php else: ?>
    <?php if (isAdmin()): ?>
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Admin Controls" aria-label="Admin Controls">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                <rect y="6" width="28" height="3.5" rx="1.75" fill="currentColor"/>
                <rect y="12.25" width="28" height="3.5" rx="1.75" fill="currentColor"/>
                <rect y="18.5" width="28" height="3.5" rx="1.75" fill="currentColor"/>
            </svg>
        </button>
        <div class="sidebar" id="adminSidebar">
            <h3>Admin Controls</h3>
            <button onclick="showModal('groupModal')">Add Group</button>
            <button onclick="showModal('guestModal')">Create Guest User</button>
            <button onclick="exportConfig()">Export Config</button>
            <button onclick="showModal('importModal')">Import Config</button>
            <hr>
            <h4>Guest Users</h4>
            <ul id="guestList" style="margin-bottom:1rem;">
                <?php
                $users = loadUsers();
                foreach ($users as $uname => $udata) {
                    if ($udata['role'] === 'guest') {
                        echo '<li><span class="guest-name">' . htmlspecialchars($uname) . '</span><button class="guest-delete-btn" onclick="deleteGuest(\'' . addslashes($uname) . '\')">Delete</button></li>';
                    }
                }
                ?>
            </ul>
            <button class="sidebar-pin" onclick="pinSidebar()">
                <span id="pinText">Pin Sidebar</span>
            </button>
        </div>
    <?php endif; ?>
    <div class="header">
        <h1>
            <?php if (isAdmin()): ?>
                <input id="dashboardTitleInput" value="<?= htmlspecialchars($config['dashboard_title'] ?? 'Dashboard') ?>" />
                <i class="fa fa-pen" style="font-size:1.1rem;opacity:0.5;margin-left:0.2rem;"></i>
            <?php else: ?>
                <?= htmlspecialchars($config['dashboard_title'] ?? 'Dashboard') ?>
            <?php endif; ?>
        </h1>
        <div class="user-info">
            <span>
                Welcome, <?= htmlspecialchars($_SESSION['user']) ?> (<?= htmlspecialchars($_SESSION['role']) ?> |
                <a href="#" onclick="showModal('passwordModal');return false;" style="color:#007aff;">update password</a>)
            </span>
            <a href="?logout=1" style="color:#007aff;text-decoration:none;">Logout</a>
        </div>
    </div>
    <div class="main-content">
        <?php foreach ($config['groups'] as $group): ?>
            <div class="group" style="--group-bg:<?= htmlspecialchars($group['color'] ?? '#f5f5f7') ?>">
                <div class="group-header">
                    <h2 class="group-title"><?= htmlspecialchars($group['name']) ?></h2>
                    <?php if (isAdmin()): ?>
                        <div class="group-actions">
                            <button class="dropdown-toggle" onclick="toggleGroupMenu(this,event)">
                                <i class="fa fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu">
                                <button onclick="addService(<?= $group['id'] ?>);event.stopPropagation();">Add Service</button>
                                <button onclick="editGroup(<?= $group['id'] ?>,'<?= htmlspecialchars($group['name']) ?>','<?= htmlspecialchars($group['color'] ?? '#f5f5f7') ?>');event.stopPropagation();">Edit Group</button>
                                <button class="btn-danger" onclick="deleteGroup(<?= $group['id'] ?>);event.stopPropagation();">Delete Group</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="services-grid">
                    <?php foreach ($group['services'] as $service): ?>
                        <div class="service-card" onclick="openService('<?= htmlspecialchars($service['url']) ?>')">
                            <span class="service-icon-wrap">
                                <?php
                                if (!empty($service['icon'])) {
                                    if (($service['icon_type'] ?? 'fa') === 'fa') {
                                        echo '<span class="service-icon"><i class="' . htmlspecialchars($service['icon']) . '"></i></span>';
                                    } elseif ($service['icon_type'] === 'emoji') {
                                        echo '<span class="service-icon">' . htmlspecialchars($service['icon']) . '</span>';
                                    } elseif ($service['icon_type'] === 'url') {
                                        echo '<img src="' . htmlspecialchars($service['icon']) . '" class="service-icon-img" alt="icon">';
                                    } elseif ($service['icon_type'] === 'upload') {
                                        echo '<img src="' . htmlspecialchars($service['icon']) . '" class="service-icon-img" alt="icon">';
                                    }
                                }
                                ?>
                            </span>
                            <div class="service-title"><?= htmlspecialchars($service['title']) ?></div>
                            <div class="service-description"><?= htmlspecialchars($service['description']) ?></div>
                            <div class="badges-row">
                                <?php if (isset($service['shared']) && $service['shared']): ?>
                                    <span class="shared-badge">Shared</span>
                                <?php endif; ?>
                                <?php if (isAdmin() && !empty($service['allowed_users'])): ?>
                                    <?php foreach ($service['allowed_users'] as $u): ?>
                                        <span class="allowed-badge" title="Allowed user"><?= htmlspecialchars($u) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (isAdmin()): ?>
                                <div class="service-actions" onclick="event.stopPropagation();">
                                    <button class="note-btn" title="Show note" onclick="showNoteModal(<?= $service['id'] ?>)">📝</button>
                                    <button class="btn-secondary" onclick="editService(<?= $service['id'] ?>,<?= htmlspecialchars(json_encode($service),ENT_QUOTES,'UTF-8') ?>)">Edit</button>
                                    <button class="btn-danger" onclick="deleteService(<?= $service['id'] ?>)">Delete</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <!-- Modals -->
    <div id="groupModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('groupModal')">&times;</span>
            <h3 id="groupModalTitle">Add Group</h3>
            <form id="groupForm">
                <input type="hidden" id="groupId" name="id">
                <div class="form-group">
                    <label for="groupName">Group Name:</label>
                    <input type="text" id="groupName" name="name" required>
                </div>
                <div class="form-group">
                    <label for="groupColor">Group Color:</label>
                    <select id="groupColor" name="color">
                        <?php foreach ($palette as $color): ?>
                            <option value="<?= $color ?>" style="background:<?= $color ?>;"><?= $color ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Save Group</button>
            </form>
        </div>
    </div>
    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('serviceModal')">&times;</span>
            <h3 id="serviceModalTitle">Add Service</h3>
            <form id="serviceForm" enctype="multipart/form-data">
                <input type="hidden" id="serviceId" name="id">
                <input type="hidden" id="serviceGroupId" name="group_id">
                <div class="form-group">
                    <label for="serviceTitle">Title:</label>
                    <input type="text" id="serviceTitle" name="title" required>
                </div>
                <div class="form-group">
                    <label for="serviceDescription">Description:</label>
                    <input type="text" id="serviceDescription" name="description" required>
                </div>
                <div class="form-group">
                    <label for="serviceUrl">URL:</label>
                    <input type="url" id="serviceUrl" name="url" required>
                </div>
                <div class="form-group">
                    <label>Icon Type:</label>
                    <div style="display:flex;gap:1rem;">
                        <label><input type="radio" name="icon_type" value="fa" checked onchange="showIconInput(this.value)"> FontAwesome</label>
                        <label><input type="radio" name="icon_type" value="emoji" onchange="showIconInput(this.value)"> Emoji</label>
                        <label><input type="radio" name="icon_type" value="url" onchange="showIconInput(this.value)"> URL</label>
                        <label><input type="radio" name="icon_type" value="upload" onchange="showIconInput(this.value)"> Upload</label>
                    </div>
                </div>
                <div class="form-group icon-input icon-fa">
                    <label for="icon_fa">FontAwesome class (e.g. fa-brands fa-slack):</label>
                    <input type="text" id="icon_fa" name="icon_fa" placeholder="fa-brands fa-slack">
                </div>
                <div class="form-group icon-input icon-emoji" style="display:none;">
                    <label for="icon_emoji">Emoji:</label>
                    <input type="text" id="icon_emoji" name="icon_emoji" maxlength="2" placeholder="😊">
                </div>
                <div class="form-group icon-input icon-url" style="display:none;">
                    <label for="icon_url">Image URL:</label>
                    <input type="url" id="icon_url" name="icon_url" placeholder="https://...">
                </div>
                <div class="form-group icon-input icon-upload" style="display:none;">
                    <label for="icon_file">Upload Image (PNG/JPG, 46x46 recommended):</label>
                    <input type="file" id="icon_file" name="icon_file" accept="image/*">
                    <input type="hidden" id="icon_upload_existing" name="icon_upload_existing">
                    <div id="icon_upload_preview" style="margin-top:0.5rem;"></div>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="serviceShared" name="shared">
                    <label for="serviceShared">Share with guests</label>
                </div>
                <div class="form-group">
                    <label for="serviceAllowedUsers">Allowed Users:</label>
                    <select id="serviceAllowedUsers" name="allowed_users[]" multiple>
                        <?php foreach (loadUsers() as $uname => $udata): ?>
                            <option value="<?= htmlspecialchars($uname) ?>"><?= htmlspecialchars($uname) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Select users who can access this service (leave empty for all)</small>
                </div>
                <div class="form-group">
                    <label for="serviceNote">Note:</label>
                    <textarea id="serviceNote" name="note" placeholder="Optional note..."></textarea>
                </div>
                <button type="submit">Save Service</button>
            </form>
        </div>
    </div>
    <div id="guestModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('guestModal')">&times;</span>
            <h3>Create Guest User</h3>
            <form id="guestForm">
                <div class="form-group">
                    <label for="guestUsername">Username:</label>
                    <input type="text" id="guestUsername" name="username" required>
                </div>
                <div class="form-group">
                    <label for="guestPassword">Password:</label>
                    <input type="password" id="guestPassword" name="password" required>
                </div>
                <button type="submit">Create Guest</button>
            </form>
            <div id="guestError" class="error" style="display:none;"></div>
        </div>
    </div>
    <div id="importModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('importModal')">&times;</span>
            <h3>Import Configuration</h3>
            <form id="importForm">
                <div class="form-group">
                    <label for="configData">Configuration JSON:</label>
                    <textarea id="configData" name="config_data" rows="10" placeholder="Paste your exported configuration here..." required></textarea>
                </div>
                <button type="submit" class="btn-success">Import Configuration</button>
            </form>
        </div>
    </div>
    <div id="noteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('noteModal')">&times;</span>
            <h3>Service Note</h3>
            <form id="noteForm">
                <input type="hidden" id="noteServiceId" name="id">
                <textarea id="noteText" name="note" placeholder="Note..."></textarea>
                <button type="submit" class="btn-success">Save Note</button>
            </form>
            <div id="noteView" style="display:none;white-space:pre-wrap;"></div>
        </div>
    </div>
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('passwordModal')">&times;</span>
            <h3>Update Password</h3>
            <form id="passwordForm">
                <div class="form-group">
                    <label for="oldPassword">Current Password:</label>
                    <input type="password" id="oldPassword" name="old_password" required>
                </div>
                <div class="form-group">
                    <label for="newPassword">New Password:</label>
                    <input type="password" id="newPassword" name="new_password" required>
                </div>
                <button type="submit" class="btn-success">Update Password</button>
            </form>
            <div id="passwordError" class="error" style="display:none;"></div>
            <div id="passwordSuccess" class="success" style="display:none;"></div>
        </div>
    </div>
    <footer class="footer">
        Developed by Uladzislau Murashka &middot; <a href="https://github.com/sm0k3net" target="_blank">github.com/sm0k3net</a>
    </footer>
    <script>
        // Sidebar logic
        let sidebarPinned = localStorage.getItem('sidebarPinned') === 'true';
        function toggleSidebar() {
            document.getElementById('adminSidebar').classList.toggle('open');
        }
        function pinSidebar() {
            sidebarPinned = !sidebarPinned;
            document.body.classList.toggle('sidebar-pinned', sidebarPinned);
            localStorage.setItem('sidebarPinned', sidebarPinned);
            document.getElementById('pinText').textContent = sidebarPinned ? 'Unpin Sidebar' : 'Pin Sidebar';
            if (!sidebarPinned) document.getElementById('adminSidebar').classList.remove('open');
        }
        window.addEventListener('DOMContentLoaded', () => {
            document.body.classList.toggle('sidebar-pinned', sidebarPinned);
            if (sidebarPinned) document.getElementById('pinText').textContent = 'Unpin Sidebar';
        });

        // Group actions dropdown
        function toggleGroupMenu(btn, e) {
            e.stopPropagation();
            document.querySelectorAll('.group-actions .dropdown-menu').forEach(menu => menu.classList.remove('show'));
            btn.nextElementSibling.classList.toggle('show');
        }
        document.body.addEventListener('click', function() {
            document.querySelectorAll('.group-actions .dropdown-menu').forEach(menu => menu.classList.remove('show'));
        });

        function showModal(modalId) { document.getElementById(modalId).style.display = 'block'; }
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            const forms = document.querySelectorAll('#'+modalId+' form');
            forms.forEach(form => form.reset());
            if (modalId === 'serviceModal') {
                document.getElementById('serviceAllowedUsers').selectedIndex = -1;
                document.getElementById('icon_upload_preview').innerHTML = '';
                document.getElementById('icon_upload_existing').value = '';
            }
            if (modalId === 'noteModal') {
                document.getElementById('noteText').value = '';
                document.getElementById('noteView').style.display = 'none';
                document.getElementById('noteText').style.display = '';
            }
            if (modalId === 'guestModal') {
                document.getElementById('guestError').style.display = 'none';
            }
            if (modalId === 'passwordModal') {
                document.getElementById('passwordError').style.display = 'none';
                document.getElementById('passwordSuccess').style.display = 'none';
            }
        }
        function openService(url) { window.open(url, '_blank'); }
        function addService(groupId) {
            document.getElementById('serviceModalTitle').textContent = 'Add Service';
            document.getElementById('serviceId').value = '';
            document.getElementById('serviceGroupId').value = groupId;
            document.getElementById('serviceTitle').value = '';
            document.getElementById('serviceDescription').value = '';
            document.getElementById('serviceUrl').value = '';
            document.getElementById('icon_fa').value = '';
            document.getElementById('icon_emoji').value = '';
            document.getElementById('icon_url').value = '';
            document.getElementById('icon_file').value = '';
            document.getElementById('icon_upload_existing').value = '';
            document.getElementById('icon_upload_preview').innerHTML = '';
            document.querySelector('input[name="icon_type"][value="fa"]').checked = true;
            showIconInput('fa');
            document.getElementById('serviceShared').checked = false;
            document.getElementById('serviceAllowedUsers').selectedIndex = -1;
            document.getElementById('serviceNote').value = '';
            showModal('serviceModal');
        }
        function editService(serviceId, serviceData) {
            document.getElementById('serviceModalTitle').textContent = 'Edit Service';
            document.getElementById('serviceId').value = serviceId;
            document.getElementById('serviceGroupId').value = '';
            document.getElementById('serviceTitle').value = serviceData.title;
            document.getElementById('serviceDescription').value = serviceData.description;
            document.getElementById('serviceUrl').value = serviceData.url;
            document.getElementById('serviceNote').value = serviceData.note || '';
            document.getElementById('serviceShared').checked = !!serviceData.shared;
            let t = serviceData.icon_type || 'fa';
            document.querySelector('input[name="icon_type"][value="'+t+'"]').checked = true;
            showIconInput(t);
            if (t === 'fa') document.getElementById('icon_fa').value = serviceData.icon;
            if (t === 'emoji') document.getElementById('icon_emoji').value = serviceData.icon;
            if (t === 'url') document.getElementById('icon_url').value = serviceData.icon;
            if (t === 'upload') {
                document.getElementById('icon_upload_existing').value = serviceData.icon;
                if (serviceData.icon) {
                    document.getElementById('icon_upload_preview').innerHTML = '<img src="'+serviceData.icon+'" style="width:46px;height:46px;border-radius:8px;">';
                } else {
                    document.getElementById('icon_upload_preview').innerHTML = '';
                }
            }
            const select = document.getElementById('serviceAllowedUsers');
            Array.from(select.options).forEach(opt => {
                opt.selected = (serviceData.allowed_users || []).includes(opt.value);
            });
            showModal('serviceModal');
        }
        function editGroup(groupId, groupName, groupColor) {
            document.getElementById('groupModalTitle').textContent = 'Edit Group';
            document.getElementById('groupId').value = groupId;
            document.getElementById('groupName').value = groupName;
            document.getElementById('groupColor').value = groupColor || '#f5f5f7';
            showModal('groupModal');
        }
        function deleteGroup(groupId) {
            if (confirm('Are you sure you want to delete this group and all its services?')) {
                makeRequest('delete_group', { id: groupId });
            }
        }
        function deleteService(serviceId) {
            if (confirm('Are you sure you want to delete this service?')) {
                makeRequest('delete_service', { id: serviceId });
            }
        }
        function deleteGuest(username) {
            if (confirm('Delete guest user ' + username + '?')) {
                makeRequest('delete_guest', {username: username}, function(resp) {
                    if (resp.success) {
                        const guestList = document.getElementById('guestList');
                        const items = guestList.querySelectorAll('li');
                        items.forEach(li => {
                            if (li.querySelector('.guest-name').textContent === username) li.remove();
                        });
                    } else {
                        alert(resp.message || 'Failed to delete guest');
                    }
                });
            }
        }
        function exportConfig() {
            makeRequest('export_config', {}, function(response) {
                if (response.success) {
                    const dataStr = JSON.stringify(response.data, null, 2);
                    const dataBlob = new Blob([dataStr], {type: 'application/json'});
                    const url = URL.createObjectURL(dataBlob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = 'dashboard_config.json';
                    link.click();
                    URL.revokeObjectURL(url);
                }
            });
        }
        function makeRequest(action, data, callback) {
            const formData = new FormData();
            formData.append('action', action);
            for (const key in data) {
                if (Array.isArray(data[key])) data[key].forEach(v => formData.append(key+'[]', v));
                else formData.append(key, data[key]);
            }
            fetch(window.location.href, { method:'POST', body:formData })
            .then(response => response.json())
            .then(data => {
                if (callback) callback(data);
                else if (data.success) location.reload();
                else alert(data.message || 'Operation failed');
            })
            .catch(error => { console.error('Error:', error); alert('An error occurred'); });
        }
        document.getElementById('groupForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const action = formData.get('id') ? 'update_group' : 'add_group';
            makeRequest(action, {
                id: formData.get('id'),
                name: formData.get('name'),
                color: formData.get('color')
            });
        });
        document.getElementById('serviceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const formData = new FormData(form);
            let action = formData.get('id') ? 'update_service' : 'add_service';
            formData.append('action', action);
            fetch(window.location.href, { method:'POST', body:formData })
            .then(response => response.json())
            .then(data => { if (data.success) location.reload(); else alert(data.message || 'Operation failed'); });
        });
        document.getElementById('guestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const username = document.getElementById('guestUsername').value.trim();
            const password = document.getElementById('guestPassword').value;
            const errorDiv = document.getElementById('guestError');
            errorDiv.style.display = 'none';
            makeRequest('create_guest', {username, password}, function(resp) {
                if (resp.success) {
                    const guestList = document.getElementById('guestList');
                    const li = document.createElement('li');
                    li.innerHTML = '<span class="guest-name">'+username+'</span><button class="guest-delete-btn" onclick="deleteGuest(\''+username+'\')">Delete</button>';
                    guestList.appendChild(li);
                    hideModal('guestModal');
                } else {
                    errorDiv.textContent = resp.message || 'Failed to create guest';
                    errorDiv.style.display = '';
                }
            });
        });
        document.getElementById('importForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const configData = document.getElementById('configData').value;
            makeRequest('import_config', {config_data: configData});
        });
        function showIconInput(type) {
            document.querySelectorAll('.icon-input').forEach(el => el.style.display = 'none');
            document.querySelector('.icon-' + type).style.display = '';
        }
        document.querySelectorAll('input[name="icon_type"]').forEach(radio => {
            radio.addEventListener('change', function() { showIconInput(this.value); });
        });
        document.getElementById('icon_file').addEventListener('change', function() {
            const preview = document.getElementById('icon_upload_preview');
            preview.innerHTML = '';
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="'+e.target.result+'" style="width:46px;height:46px;border-radius:8px;">';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
        function showNoteModal(serviceId) {
            event.stopPropagation();
            showModal('noteModal');
            document.getElementById('noteServiceId').value = serviceId;
            document.getElementById('noteText').style.display = '';
            document.getElementById('noteForm').style.display = '';
            document.getElementById('noteView').style.display = 'none';
            fetch(window.location.href, {
                method: 'POST',
                body: new URLSearchParams({action: 'get_note', id: serviceId})
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('noteText').value = data.note || '';
                document.getElementById('noteView').textContent = data.note || '';
            });
        }
        document.getElementById('noteForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const serviceId = document.getElementById('noteServiceId').value;
            const note = document.getElementById('noteText').value;
            makeRequest('set_note', {id: serviceId, note: note}, function(resp) {
                if (resp.success) {
                    document.getElementById('noteView').textContent = note;
                    document.getElementById('noteView').style.display = '';
                    document.getElementById('noteText').style.display = 'none';
                    document.getElementById('noteForm').style.display = 'none';
                }
            });
        });
        <?php if (isAdmin()): ?>
        document.getElementById('dashboardTitleInput').addEventListener('change', function() {
            makeRequest('update_dashboard_title', {title: this.value}, function() { location.reload(); });
        });
        <?php endif; ?>
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const old_password = document.getElementById('oldPassword').value;
            const new_password = document.getElementById('newPassword').value;
            const errorDiv = document.getElementById('passwordError');
            const successDiv = document.getElementById('passwordSuccess');
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';
            makeRequest('change_password', {old_password, new_password}, function(resp) {
                if (resp.success) {
                    successDiv.textContent = 'Password updated successfully!';
                    successDiv.style.display = '';
                    setTimeout(() => hideModal('passwordModal'), 1200);
                } else {
                    errorDiv.textContent = resp.message || 'Failed to update password';
                    errorDiv.style.display = '';
                }
            });
        });
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => { if (event.target === modal) modal.style.display = 'none'; });
        });
    </script>
<?php endif; ?>
</body>
</html>
