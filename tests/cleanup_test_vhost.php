<?php

/**
 * Этот скрипт удаляет все очереди и кастомные exchange из указанного виртуального хоста RabbitMQ.
 * Используется HTTP API Management Plugin.
 */

$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$apiPort = getenv('RABBITMQ_API_PORT') ?: 15672;
$user = getenv('RABBITMQ_USER') ?: 'root';
$pass = getenv('RABBITMQ_PASSWORD') ?: 'secret';
$vhost = getenv('RABBITMQ_VHOST') ?: 'test';

echo "Cleaning up RabbitMQ vhost '{$vhost}' on {$host}:{$apiPort}...\n";

$baseUrl = "http://{$host}:{$apiPort}/api";
$vhostEncoded = urlencode($vhost);
$contextOptions = [
    'http' => [
        'header' => "Authorization: Basic " . base64_encode("{$user}:{$pass}"),
        'ignore_errors' => true,
        'timeout' => 5,
    ]
];
$context = stream_context_create($contextOptions);

// Проверяем доступность API
$check = @file_get_contents("{$baseUrl}/overview", false, $context);
if ($check === false) {
    echo "Warning: Could not connect to RabbitMQ Management API at {$baseUrl}. Is it running?\n";
    exit(0); // Выходим с 0, чтобы не ронять CI пайплайны
}

// 1. Очистка очередей
echo "Fetching queues...\n";
$queuesJson = @file_get_contents("{$baseUrl}/queues/{$vhostEncoded}", false, $context);
if ($queuesJson) {
    $queues = json_decode($queuesJson, true);
    if (is_array($queues)) {
        $count = 0;
        foreach ($queues as $queue) {
            $qName = urlencode($queue['name']);
            $delContextOptions = $contextOptions;
            $delContextOptions['http']['method'] = 'DELETE';
            @file_get_contents("{$baseUrl}/queues/{$vhostEncoded}/{$qName}", false, stream_context_create($delContextOptions));
            $count++;
        }
        echo "Deleted {$count} queues.\n";
    }
}

// 2. Очистка Exchange (кроме системных)
echo "Fetching exchanges...\n";
$exchangesJson = @file_get_contents("{$baseUrl}/exchanges/{$vhostEncoded}", false, $context);
if ($exchangesJson) {
    $exchanges = json_decode($exchangesJson, true);
    if (is_array($exchanges)) {
        $count = 0;
        foreach ($exchanges as $exchange) {
            $eName = $exchange['name'];
            
            // Пропускаем дефолтные эксчейнджи RabbitMQ (amq.* и дефолтный пустой)
            if ($eName === '' || strpos($eName, 'amq.') === 0) {
                continue;
            }
            
            $eNameEncoded = urlencode($eName);
            $delContextOptions = $contextOptions;
            $delContextOptions['http']['method'] = 'DELETE';
            @file_get_contents("{$baseUrl}/exchanges/{$vhostEncoded}/{$eNameEncoded}", false, stream_context_create($delContextOptions));
            $count++;
        }
        echo "Deleted {$count} custom exchanges.\n";
    }
}

echo "Cleanup complete!\n";
