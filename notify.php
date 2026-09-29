<?php
declare(strict_types=1);

// Запускается только из командной строки (например, через cron).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/src/app.php';
$config = require __DIR__ . '/config.php';

function send_email_reminder(string $to, string $from, string $message): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) ||
        !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Проверьте email-адреса в config.php.');
    }

    $subject = '=?UTF-8?B?' . base64_encode('Пора поменять линзы') . '?=';

    $headers = [
        'From' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
    ];

    if (!mail($to, $subject, $message, $headers)) {
        throw new RuntimeException(
            'Хостинг не принял письмо. Проверьте, разрешена ли функция mail() в PHP.'
        );
    }
}

try {
    $to = (string) ($config['notification_email'] ?? '');
    $from = (string) ($config['sender_email'] ?? '');

    update_state(static function (array &$state) use ($to, $from): void {
        if ($state['replacements'] === []) {
            return;
        }

        $last = new DateTimeImmutable(end($state['replacements']));
        $due = $last->modify('+5 seconds');
        // $due = $last->modify('+14 days');
        $cycle = $last->format(DateTimeInterface::ATOM);

        if (new DateTimeImmutable() < $due ||
            ($state['notified_for'] ?? null) === $cycle) {
            return;
        }

        $message = 'Пора поменять линзы. Срок текущей пары истёк '
            . format_date($due)
            . '. После замены нажмите «Поменял линзы» на сайте.';

        send_email_reminder($to, $from, $message);

        $state['notified_for'] = $cycle;
        echo "Письмо передано на отправку.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}