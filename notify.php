<?php
declare(strict_types=1);

// Запускается планировщиком задач, но не открывается через браузер.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/src/app.php';
$config = require __DIR__ . '/config.php';

function send_telegram_message(string $token, string $chatId, string $text): void
{
    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('В PHP необходимо включить allow_url_fopen для отправки в Telegram.');
    }

    // Тот же способ отправки, что в обработчике формы: HTTPS-запрос через PHP.
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage?' . http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
    ]);
    $context = stream_context_create(['http' => [
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    // Предупреждение PHP может содержать URL с токеном, поэтому не выводим его в журнал.
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('PHP не удалось соединиться с Telegram. Проверьте доступ к api.telegram.org с этого сервера.');
    }
    $answer = json_decode($response, true);
    if (!is_array($answer) || ($answer['ok'] ?? false) !== true) {
        $description = is_array($answer) ? (string) ($answer['description'] ?? 'неизвестная ошибка') : 'неверный ответ';
        throw new RuntimeException('Telegram отклонил сообщение: ' . $description);
    }
}

try {
    $token = (string) ($config['telegram_bot_token'] ?? '');
    $chatId = (string) ($config['telegram_chat_id'] ?? '');
    if ($token === '' || $chatId === '' || str_starts_with($token, 'ТОКЕН_') || $chatId === 'ВАШ_CHAT_ID') {
        throw new RuntimeException('Укажите telegram_bot_token и telegram_chat_id в config.php.');
    }

    update_state(static function (array &$state) use ($token, $chatId): void {
        if ($state['replacements'] === []) {
            return;
        }
        $last = new DateTimeImmutable(end($state['replacements']));
        $due = $last->modify('+5 seconds');
        // $due = $last->modify('+14 days');
        $cycle = $last->format(DateTimeInterface::ATOM);
        if (new DateTimeImmutable() < $due || ($state['notified_for'] ?? null) === $cycle) {
            return;
        }

        // Блокировка файла удерживается до ответа Telegram: соседние запуски
        // планировщика не отправят одно напоминание одновременно.
        send_telegram_message($token, $chatId, 'Пора поменять линзы. Срок текущей пары истёк ' . format_date($due) . '. После замены нажмите «Поменял линзы» на сайте.');
        $state['notified_for'] = $cycle;
        echo "Напоминание отправлено.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
