<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Internal SELECT provenance for a financial snapshot; never accepts SQL from a request. */
class SundryCalculationProof
{
    private static array $applicationQueries = [];

    public function __construct(private readonly ConnectionInterface $db) {}

    /** Eligibility checks must run before posting, but are not historical ledger facts. */
    public function applicationOnly(callable $check): mixed
    {
        $start = count($this->db->getQueryLog());
        try {
            return $check();
        } finally {
            $id = spl_object_id($this->db);
            if (isset(self::$applicationQueries[$id])) {
                for ($i = $start; $i < count($this->db->getQueryLog()); $i++) {
                    self::$applicationQueries[$id][$i] = true;
                }
            }
        }
    }

    public function historical(array $proof): array
    {
        return array_values(array_filter($proof, fn (array $query): bool => ($query['scope'] ?? 'historical') !== 'application'));
    }

    public function capture(callable $calculate): array
    {
        $wasLogging = $this->db->logging();
        $start = count($this->db->getQueryLog());
        $id = spl_object_id($this->db);
        if (isset(self::$applicationQueries[$id])) {
            throw new RuntimeException('計算根拠の記録が重複しています。');
        }
        self::$applicationQueries[$id] = [];
        $this->db->enableQueryLog();
        try {
            $result = $calculate();
            $queries = array_slice($this->db->getQueryLog(), $start);
            $applicationQueries = self::$applicationQueries[$id];
        } finally {
            unset(self::$applicationQueries[$id]);
            if (! $wasLogging) {
                $this->db->disableQueryLog();
                $this->db->flushQueryLog();
            }
        }
        $proof = [];
        foreach ($queries as $index => $query) {
            if (! preg_match('/^select\s/i', ltrim($query['query']))
                || preg_match('/information_schema|sqlite_master|pragma_table/i', $query['query'])) {
                continue;
            }
            $bindings = $this->db->prepareBindings($query['bindings']);
            $key = hash('sha256', $query['query'].json_encode($bindings, JSON_THROW_ON_ERROR));
            $scope = isset($applicationQueries[$start + $index]) ? 'application' : 'historical';
            if (($proof[$key]['scope'] ?? null) !== 'historical') {
                $proof[$key] = ['sql' => $query['query'], 'bindings' => $bindings, 'scope' => $scope];
            }
        }
        return [$result, $this->refresh(array_values($proof))];
    }

    public function verify(array $proof): void
    {
        if ($proof === []) {
            throw new RuntimeException('計算根拠が保存されていません。最終確定の確認をやり直してください。');
        }
        foreach ($proof as $query) {
            if (! hash_equals($query['hash'], $this->digest($query))) {
                throw new RuntimeException('確定後に受払・原価・分類・入力・前残が変更されています。適用せず停止しました。確定差異を再確認してください。');
            }
        }
    }

    public function refresh(array $proof): array
    {
        foreach ($proof as &$query) {
            $query['hash'] = $this->digest($query);
        }
        return $proof;
    }

    private function digest(array $query): string
    {
        $sql = $query['sql'] ?? '';
        if (! preg_match('/^select\s/i', ltrim($sql))
            || preg_match('/;|\\b(into|outfile|dumpfile|sleep|benchmark)\\b|for\\s+update|:=/i', $sql)) {
            throw new RuntimeException('計算根拠の読取クエリが不正です。処理を停止しました。');
        }
        $rows = array_map(function ($row): string {
            $values = (array) $row;
            ksort($values);
            foreach ($values as &$value) {
                $value = $value === null ? null : (string) $value;
            }
            return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }, $this->db->select($sql, $query['bindings']));
        sort($rows, SORT_STRING);
        return hash('sha256', implode("\n", $rows));
    }
}
