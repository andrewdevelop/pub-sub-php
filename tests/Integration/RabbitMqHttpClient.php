<?php

namespace Core\Messaging;

class RabbitMqHttpClient
{
    private string $baseUrl;
    private $context;

    public function __construct(string $host, int $amqpPort, string $user, string $pass, string $vhost)
    {
        // Standard mapping: AMQP 5672 -> HTTP 15672
        $apiPort = $amqpPort + 10000;
        $this->baseUrl = "http://{$host}:{$apiPort}/api";
        $vhostEncoded = urlencode($vhost);
        $this->baseUrl .= "/exchanges/{$vhostEncoded}"; // Base for exchanges, queues are slightly different

        $this->context = stream_context_create([
            'http' => [
                'header' => "Authorization: Basic " . base64_encode("{$user}:{$pass}"),
                'ignore_errors' => true,
            ]
        ]);
    }

    private function getBaseUrlFor(string $type, string $vhost): string
    {
        $apiPort = parse_url($this->baseUrl, PHP_URL_PORT);
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        return "http://{$host}:{$apiPort}/api/{$type}/" . urlencode($vhost);
    }

    public function deleteExchange(string $name): void
    {
        $vhost = urldecode(basename($this->baseUrl));
        $url = $this->getBaseUrlFor('exchanges', $vhost) . '/' . urlencode($name);
        $context = stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'header' => "Authorization: Basic " . explode(' ', stream_context_get_options($this->context)['http']['header'])[2],
                'ignore_errors' => true,
            ]
        ]);
        file_get_contents($url, false, $context);
    }

    public function listQueues(): array
    {
        $vhost = urldecode(basename($this->baseUrl));
        $url = $this->getBaseUrlFor('queues', $vhost);
        $res = file_get_contents($url, false, $this->context);
        if (!$res) return [];
        return json_decode($res, true) ?? [];
    }

    public function deleteQueue(string $name): void
    {
        $vhost = urldecode(basename($this->baseUrl));
        $url = $this->getBaseUrlFor('queues', $vhost) . '/' . urlencode($name);
        $context = stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'header' => "Authorization: Basic " . explode(' ', stream_context_get_options($this->context)['http']['header'])[2],
                'ignore_errors' => true,
            ]
        ]);
        file_get_contents($url, false, $context);
    }

    public function purgeQueue(string $name): void
    {
        $vhost = urldecode(basename($this->baseUrl));
        $url = $this->getBaseUrlFor('queues', $vhost) . '/' . urlencode($name) . '/contents';
        $context = stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'header' => "Authorization: Basic " . explode(' ', stream_context_get_options($this->context)['http']['header'])[2],
                'ignore_errors' => true,
            ]
        ]);
        file_get_contents($url, false, $context);
    }

    public function deleteAllQueuesMatching(string $pattern): void
    {
        $queues = $this->listQueues();
        foreach ($queues as $queue) {
            if (fnmatch($pattern, $queue['name'])) {
                $this->purgeQueue($queue['name']);
                $this->deleteQueue($queue['name']);
            }
        }
    }
    
    public function queueExists(string $name): bool
    {
        $vhost = urldecode(basename($this->baseUrl));
        $url = $this->getBaseUrlFor('queues', $vhost) . '/' . urlencode($name);
        $res = file_get_contents($url, false, $this->context);
        if (!$res) return false;
        
        $http_response_header = $http_response_header ?? [];
        if (!empty($http_response_header) && strpos($http_response_header[0], '404') !== false) {
            return false;
        }
        return true;
    }

    public function listConnections(): array
    {
        $apiPort = parse_url($this->baseUrl, PHP_URL_PORT);
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        $url = "http://{$host}:{$apiPort}/api/connections";
        $res = file_get_contents($url, false, $this->context);
        if (!$res) return [];
        return json_decode($res, true) ?? [];
    }

    public function deleteConnection(string $name): void
    {
        $apiPort = parse_url($this->baseUrl, PHP_URL_PORT);
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        $url = "http://{$host}:{$apiPort}/api/connections/" . urlencode($name);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'header' => "Authorization: Basic " . explode(' ', stream_context_get_options($this->context)['http']['header'])[2],
                'ignore_errors' => true,
            ]
        ]);
        file_get_contents($url, false, $context);
    }
}
