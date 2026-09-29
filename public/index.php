<?php
declare(strict_types=1);

require __DIR__ . '/../src/app.php';
$config = require __DIR__ . '/../config.php';

// Одна личная учётная запись. Публиковать сайт следует только по HTTPS.
$siteUser = (string) ($config['site_user'] ?? 'lens');
$password = (string) ($config['site_password'] ?? '');
if ($password === '' || $password === 'ЗАМЕНИТЕ_НА_СВОЙ_ПАРОЛЬ') {
    http_response_code(503);
    exit('Сначала укажите свой пароль в config.php.');
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$loginKey = hash('sha256', $siteUser . "\0" . $password);
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Неверный токен формы. Обновите страницу.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $user = (string) ($_POST['user'] ?? '');
        $givenPassword = (string) ($_POST['password'] ?? '');
        if (hash_equals($siteUser, $user) && hash_equals($password, $givenPassword)) {
            session_regenerate_id(true);
            $_SESSION['login_key'] = $loginKey;
            header('Location: /', true, 303);
            exit;
        }
        $loginError = 'Неверное имя пользователя или пароль.';
    } elseif ($action === 'logout') {
        unset($_SESSION['login_key']);
        session_regenerate_id(true);
        header('Location: /', true, 303);
        exit;
    }
}

if (!hash_equals($loginKey, (string) ($_SESSION['login_key'] ?? ''))) {
    http_response_code(401);
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Вход — замена линз</title>
        <link rel="stylesheet" href="/style.css">
    </head>
    <body>
    <main class="container login-container">
        <section class="card" aria-labelledby="login-heading">
            <h1 id="login-heading">Вход</h1>
            <p class="detail">Календарь замены линз</p>
            <?php if ($loginError !== ''): ?><p class="login-error" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <label for="user">Имя пользователя</label>
                <input id="user" name="user" autocomplete="username" required autofocus>
                <label for="password">Пароль</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button type="submit">Войти</button>
            </form>
        </section>
    </main>
    </body>
    </html>
    <?php
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['action'] ?? '') !== 'replace') {
            http_response_code(400);
            exit('Неизвестное действие.');
        }
        update_state(static function (array &$state): void {
            $state['replacements'][] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $state['notified_for'] = null;
        });
        header('Location: /?saved=1', true, 303);
        exit;
    }
    $state = read_state();
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(500);
    exit('Не удалось открыть историю. Проверьте права на папку data и журнал сервера.');
}

$replacements = $state['replacements'];
$last = $replacements === [] ? null : new DateTimeImmutable(end($replacements));
$due = $last?->modify('+14 days');
$now = new DateTimeImmutable();
$remaining = $due === null ? null : $due->getTimestamp() - $now->getTimestamp();
$days = $remaining === null ? 0 : (int) ceil($remaining / 86400);
$status = $due === null ? 'Начните отсчёт' : ($remaining <= 0 ? 'Пора менять линзы' : 'Следующая замена');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Замена линз</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="container">
    <header class="page-header">
        <form method="post" class="logout-form">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Выйти</button>
        </form>
        <span class="eyebrow">ЛИЧНЫЙ КАЛЕНДАРЬ</span>
        <h1>Замена линз</h1>
        <p>Новая пара каждые 14 дней.</p>
    </header>

    <section class="card" aria-labelledby="status-heading">
        <div class="card-top">
            <span class="label" id="status-heading"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="period">14 дней</span>
        </div>
        <p class="due-date<?= $remaining !== null && $remaining <= 0 ? ' overdue' : '' ?>">
            <?= $due ? htmlspecialchars(format_date($due), ENT_QUOTES, 'UTF-8') : 'Дата пока не задана' ?>
        </p>
        <p class="detail">
            <?php if ($due === null): ?>Нажмите кнопку после установки новой пары.
            <?php elseif ($remaining <= 0): ?>Срок этой пары истёк. После замены начнётся новый отсчёт.
            <?php elseif ($days === 1): ?>Осталось меньше суток.
            <?php else: ?>Осталось примерно <?= $days ?> дн.
            <?php endif; ?>
        </p>
        <?php if (isset($_GET['saved'])): ?><p class="success" role="status">Замена записана в историю.</p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="replace">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Поменял линзы</button>
        </form>
    </section>

    <section class="history" aria-labelledby="history-heading">
        <div class="history-title"><h2 id="history-heading">История замен</h2><span><?= count($replacements) ?></span></div>
        <?php if ($replacements === []): ?>
            <p class="empty">Пока нет записей. Первая замена появится здесь.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th scope="col">№</th><th scope="col">Поменял</th><th scope="col">Следующая замена</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($replacements, true) as $index => $date): ?>
                    <?php $changed = new DateTimeImmutable($date); ?>
                    <tr><td><?= $index + 1 ?></td><td><?= htmlspecialchars(format_date($changed), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(format_date(replacement_due($date)), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
