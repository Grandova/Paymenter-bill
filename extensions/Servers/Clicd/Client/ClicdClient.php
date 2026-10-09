<?php

namespace Paymenter\Extensions\Servers\Clicd\Client;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClicdClient
{
    public function __construct(protected array $config) {}

    public function request(string $method, string $path, array $data = [], int $timeout = 30): mixed
    {
        $response = Http::baseUrl(rtrim($this->config['api_url'], '/') . '/api/v1')
            ->withHeaders(['X-API-Key' => $this->config['api_key']])
            ->acceptJson()
            ->withOptions(['verify' => (bool) ($this->config['verify_ssl'] ?? true)])
            ->connectTimeout(10)->timeout($timeout)
            ->send($method, $path, [$method === 'GET' ? 'query' : 'json' => $data]);

        $response->throw();
        if ($response->json('success') !== true) {
            throw new RuntimeException($response->json('message') ?: '服务器节点返回了无效响应');
        }

        return $response->json('data');
    }

    public function container(string $id, string $action = '', string $method = 'GET', array $data = []): mixed
    {
        return $this->request($method, 'containers/' . rawurlencode($id) . ($action ? '/' . $action : ''), $data, $method === 'GET' ? 20 : 90);
    }
}
