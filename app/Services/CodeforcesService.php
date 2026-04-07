<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CodeforcesService
{
    private string $baseUrl;
    private ?string $apiKey;
    private ?string $apiSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.codeforces.base_url', 'https://codeforces.com/api'), '/');
        $this->apiKey = config('services.codeforces.key');
        $this->apiSecret = config('services.codeforces.secret');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->apiSecret);
    }

    public function health(): array
    {
        $result = $this->request('system.status');

        return [
            'configured' => $this->isConfigured(),
            'signed_request' => true,
            'cf_status' => $result,
        ];
    }

    public function getUserInfo(string $handle): array
    {
        $result = $this->request('user.info', [
            'handles' => $handle,
            'checkHistoricHandles' => 'true',
        ]);

        return $result[0] ?? [];
    }

    public function getUserStatus(string $handle, int $count = 20): array
    {
        return $this->request('user.status', [
            'handle' => $handle,
            'from' => 1,
            'count' => max(1, min($count, 100)),
        ]);
    }

    public function getProblemByContestAndIndex(int $contestId, string $index): array
    {
        $result = $this->request('problemset.problems');
        $problems = $result['problems'] ?? [];
        $statistics = $result['problemStatistics'] ?? [];

        $problem = null;
        foreach ($problems as $item) {
            if (($item['contestId'] ?? null) === $contestId && strcasecmp((string) ($item['index'] ?? ''), $index) === 0) {
                $problem = $item;
                break;
            }
        }

        if ($problem === null) {
            throw new RuntimeException("Problem {$contestId}{$index} tidak ditemukan di Codeforces.");
        }

        $problemStatistics = null;
        foreach ($statistics as $item) {
            if (($item['contestId'] ?? null) === $contestId && strcasecmp((string) ($item['index'] ?? ''), $index) === 0) {
                $problemStatistics = $item;
                break;
            }
        }

        return [
            'problem' => $problem,
            'problem_statistics' => $problemStatistics,
            'problem_url' => "https://codeforces.com/problemset/problem/{$contestId}/{$problem['index']}",
            'statement_available_via_api' => false,
        ];
    }

    private function request(string $method, array $params = []): array
    {
        $this->assertConfigured();

        $queryParams = $this->signParameters($method, $params);
        $queryString = $this->buildQueryString($queryParams);

        $response = Http::acceptJson()
            ->timeout(15)
            ->get("{$this->baseUrl}/{$method}?{$queryString}");

        if ($response->failed()) {
            throw new RuntimeException('Gagal menghubungi Codeforces API.');
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            throw new RuntimeException('Respons Codeforces API tidak valid.');
        }

        if (($payload['status'] ?? null) !== 'OK') {
            throw new RuntimeException($payload['comment'] ?? 'Codeforces API mengembalikan error.');
        }

        return $payload['result'] ?? [];
    }

    private function signParameters(string $method, array $params = []): array
    {
        $params = array_filter($params, static fn ($value) => $value !== null && $value !== '');
        $params['apiKey'] = (string) $this->apiKey;
        $params['time'] = (string) time();

        $rand = bin2hex(random_bytes(3));
        $queryString = $this->buildQueryString($params);
        $signatureBase = "{$rand}/{$method}?{$queryString}#{$this->apiSecret}";
        $params['apiSig'] = $rand . hash('sha512', $signatureBase);

        return $params;
    }

    private function buildQueryString(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            $pairs[] = [
                'key' => (string) $key,
                'value' => (string) $value,
            ];
        }

        usort($pairs, static function (array $left, array $right): int {
            $keyComparison = strcmp($left['key'], $right['key']);

            if ($keyComparison !== 0) {
                return $keyComparison;
            }

            return strcmp($left['value'], $right['value']);
        });

        return implode('&', array_map(
            static fn (array $pair): string => rawurlencode($pair['key']) . '=' . rawurlencode($pair['value']),
            $pairs
        ));
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Konfigurasi Codeforces API belum lengkap di server.');
        }
    }
}
