<?php

function dataDirectory(): string
{
    $path = getenv('DASHBOARD_DATA_DIR');
    $dir = $path ? realpath($path) : false;
    $webRoot = realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: __DIR__);
    if (!$dir || !$webRoot || !is_dir($dir) || !is_writable($dir) || $dir === '/'
        || $dir === $webRoot || str_starts_with($dir, rtrim($webRoot, '/') . '/')
        || $dir === __DIR__ || str_starts_with($dir, __DIR__ . '/')) {
        throw new RuntimeException('Set DASHBOARD_DATA_DIR to a writable directory outside the web root.');
    }
    return $dir;
}

function readJson(string $path, array $default): array
{
    if (!file_exists($path)) {
        return $default;
    }
    $contents = file_get_contents($path);
    $data = $contents === false ? null : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid dashboard data file.');
    }
    return $data;
}

function writeJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam(dirname($path), '.dashboard-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create a temporary data file.');
    }
    try {
        if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
            throw new RuntimeException('Could not save dashboard data.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function saveConfig(array $config): void { writeJson(CONFIG_FILE, $config); }
function saveUsers(array $users): void { writeJson(USERS_FILE, $users); }

function defaultConfig(): array
{
    return [
        'dashboard_title' => 'Dashboard',
        'groups' => [],
        'next_group_id' => 1,
        'next_service_id' => 1,
    ];
}

function validPassword(string $password): bool
{
    return strlen($password) >= 12 && strlen($password) <= 72 && !str_contains($password, "\0");
}

function post(string $name, string $default = ''): string
{
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : $default;
}

function httpUrl(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true);
}

function groupColor(string $color): string
{
    return preg_match('/^#[0-9a-f]{6}$/iD', $color) ? $color : '#f5f5f7';
}

function uploadedIcon(string $icon): bool
{
    return (bool) preg_match('~^icons/icon_[a-f0-9]+\.png$~D', $icon);
}

function validId(mixed $id): bool
{
    return is_int($id) && $id > 0 && $id < PHP_INT_MAX - 1;
}

function validateService(array $service): void
{
    foreach (['title', 'description', 'url', 'icon', 'icon_type', 'note'] as $field) {
        if (!is_string($service[$field] ?? null)) {
            throw new InvalidArgumentException('Invalid service field.');
        }
    }
    if (trim($service['title']) === '' || !httpUrl($service['url'])) {
        throw new InvalidArgumentException('Enter a title and an HTTP or HTTPS service URL.');
    }
    if (!is_bool($service['shared'] ?? null) || !is_array($service['allowed_users'] ?? null)) {
        throw new InvalidArgumentException('Invalid sharing settings.');
    }
    foreach ($service['allowed_users'] as $user) {
        if (!is_string($user)) {
            throw new InvalidArgumentException('Invalid guest username.');
        }
    }
    $icon = $service['icon'];
    $validIcon = match ($service['icon_type']) {
        'fa', 'emoji' => true,
        'url' => $icon === '' || httpUrl($icon),
        'upload' => $icon === '' || uploadedIcon($icon),
        default => false,
    };
    if (!$validIcon) {
        throw new InvalidArgumentException('Invalid service icon.');
    }
}

function importConfig(string $json): array
{
    $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_string($config['dashboard_title'] ?? null)
        || !is_array($config['groups'] ?? null) || !array_is_list($config['groups'])) {
        throw new InvalidArgumentException('Import a dashboard configuration exported by Easy Dashboard.');
    }
    $groupIds = [];
    $serviceIds = [];
    foreach ($config['groups'] as &$group) {
        if (!validId($group['id'] ?? null) || isset($groupIds[$group['id']])
            || !is_string($group['name'] ?? null) || trim($group['name']) === ''
            || !is_array($group['services'] ?? null) || !array_is_list($group['services'])) {
            throw new InvalidArgumentException('Invalid group or duplicate group ID.');
        }
        $groupIds[$group['id']] = true;
        $group['color'] = groupColor(is_string($group['color'] ?? null) ? $group['color'] : '');
        foreach ($group['services'] as &$service) {
            if (!validId($service['id'] ?? null) || isset($serviceIds[$service['id']])) {
                throw new InvalidArgumentException('Invalid or duplicate service ID.');
            }
            $serviceIds[$service['id']] = true;
            $service += ['note' => '', 'shared' => false, 'allowed_users' => []];
            validateService($service);
        }
        unset($service);
    }
    unset($group);
    foreach (['next_group_id' => $groupIds, 'next_service_id' => $serviceIds] as $key => $ids) {
        $next = $config[$key] ?? null;
        $config[$key] = max(validId($next) ? $next : 1, max([0, ...array_keys($ids)]) + 1);
    }
    return $config;
}

function saveIcon(array $file): string
{
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Upload a PNG or JPEG image up to 2 MB.');
    }
    if (!extension_loaded('gd')) {
        throw new InvalidArgumentException('Image uploads require the PHP GD extension.');
    }
    $details = @getimagesize($file['tmp_name']);
    if (!$details || !in_array($details[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
        || $details[0] > 2048 || $details[1] > 2048) {
        throw new InvalidArgumentException('Upload a PNG or JPEG image at most 2048 pixels per side.');
    }
    $source = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$source) {
        throw new InvalidArgumentException('The image could not be decoded.');
    }
    $icon = imagecreatetruecolor(46, 46);
    imagealphablending($icon, false);
    imagesavealpha($icon, true);
    imagecopyresampled($icon, $source, 0, 0, 0, 0, 46, 46, $details[0], $details[1]);
    if (!is_dir(DATA_DIR . '/icons') || !is_writable(DATA_DIR . '/icons')) {
        throw new RuntimeException('The private icons directory is missing or unwritable.');
    }
    $name = 'icons/icon_' . bin2hex(random_bytes(16)) . '.png';
    $path = DATA_DIR . '/' . $name;
    if (!imagepng($icon, $path) || !is_file($path) || filesize($path) === 0) {
        @unlink($path);
        throw new RuntimeException('Could not save the uploaded icon.');
    }
    return $name;
}
