<?php

namespace Core\Messaging;

class RabbitMqHttpClient
{
    private string $apiBase;
    private string $vhost;
    private string $authHeader;

    public function __construct(string $host, int $amqpPort, string $user, string $pass, string $vhost)
    {
        // Standard mapping: AMQP 5672 -> HTTP 15672
        $apiPort = $amqpPort + 10000;
        $this->apiBase = "http://{$host}:{$apiPort}/api";
        $this->vhost = $vhost;
        $this->authHeader = "Authorization: Basic " . base64_encode("{$user}:{$pass}");
    }

    private function request(string $method, string $url, ?string $body = null): string|false
    {
        $options = [
            'http' => [
                'method'        => $method,
                'header'        => $this->authHeader,
                'ignore_errors' => true,
            ]
        ];
        if ($body !== null) {
            $options['http']['content'] = $body;
            $options['http']['header'] .= "\r\nContent-Type: application/json";
        }
        return file_get_contents($url, false, stream_context_create($options));
    }

    public function deleteExchange(string $name): void
    {
        $url = $this->apiBase . '/exchanges/' . urlencode($this->vhost) . '/' . urlencode($name);
        $this->request('DELETE', $url);
    }

    public function listQueues(): array
    {
        $url = $this->apiBase . '/queues/' . urlencode($this->vhost);
        $res = $this->request('GET', $url);
        if (!$res) return [];
        return json_decode($res, true) ?? [];
    }

    public function deleteQueue(string $name): void
    {
        $url = $this->apiBase . '/queues/' . urlencode($this->vhost) . '/' . urlencode($name);
        $this->request('DELETE', $url);
    }

    public function purgeQueue(string $name): void
    {
        $url = $this->apiBase . '/queues/' . urlencode($this->vhost) . '/' . urlencode($name) . '/contents';
        $this->request('DELETE', $url);
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
        $url = $this->apiBase . '/queues/' . urlencode($this->vhost) . '/' . urlencode($name);
        $res = $this->request('GET', $url);
        if (!$res) return false;

        $data = json_decode($res, true);
        return isset($data['name']);
    }

    public function listConnections(): array
    {
        $url = $this->apiBase . '/connections';
        $res = $this->request('GET', $url);
        if (!$res) return [];
        return json_decode($res, true) ?? [];
    }

    public function deleteConnection(string $name): void
    {
        $url = $this->apiBase . '/connections/' . urlencode($name);
        $this->request('DELETE', $url);
    }
}
