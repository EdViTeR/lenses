<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

const STATE_FILE = __DIR__ . '/../data/state.json';

function load_state($handle): array
{
    rewind($handle);
    $contents = stream_get_contents($handle);
    if ($contents === false) {
        throw new RuntimeException('Не удалось прочитать историю замен.');
    }
    if (trim($contents) === '') {
        return ['replacements' => [], 'notified_for' => null];
    }
    $state = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state) || !isset($state['replacements']) || !is_array($state['replacements'])) {
        throw new RuntimeException('Файл истории повреждён.');
    }
    return $state;
}

function read_state(): array
{
    $handle = fopen(STATE_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Нет доступа к файлу истории.');
    }
    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Не удалось заблокировать файл истории.');
        }
        return load_state($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function update_state(callable $action): mixed
{
    $handle = fopen(STATE_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Нет доступа к файлу истории.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Не удалось заблокировать файл истории.');
        }
        $state = load_state($handle);
        $result = $action($state);
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        rewind($handle);
        $payload = $json . "\n";
        $written = 0;
        while ($written < strlen($payload)) {
            $count = fwrite($handle, substr($payload, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Не удалось сохранить историю замен.');
            }
            $written += $count;
        }
        if (!ftruncate($handle, $written) || !fflush($handle)) {
            throw new RuntimeException('Не удалось сохранить историю замен.');
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function replacement_due(string $date): DateTimeImmutable
{
    return (new DateTimeImmutable($date))->modify('+14 days');
}

function format_date(DateTimeImmutable $date): string
{
    return $date->format('d.m.Y, H:i');
}
