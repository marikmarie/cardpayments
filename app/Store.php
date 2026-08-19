<?php
declare(strict_types=1);

namespace App;

final class Store
{
    private string $file;
    private ?\PDO $database = null;

    public function __construct()
    {
        $this->file = dirname(__DIR__) . '/' . Config::get('STORAGE_PATH', 'storage/data.json');
        $dsn = Config::get('DB_DSN');
        if ($dsn) {
            try {
                $this->database = new \PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASSWORD'), [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
            } catch (\PDOException $e) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
            }
            return;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
    }

    public function read(string $table): array
    {
        return $this->transaction(fn(array $data) => $data[$table] ?? [], false);
    }

    public function transaction(callable $callback, bool $write = true): mixed
    {
        if ($this->database) {
            return $this->databaseTransaction($callback, $write);
        }
        $handle = fopen($this->file, 'c+');
        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $data = json_decode($raw ?: '{}', true) ?: [];
        $result = $callback($data);

        if ($write) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $result;
    }

    private function databaseTransaction(callable $callback, bool $write): mixed
    {
        $this->database->beginTransaction();
        try {
            $query = $write
                ? 'SELECT state FROM app_state WHERE id = 1 FOR UPDATE'
                : 'SELECT state FROM app_state WHERE id = 1';
            $state = $this->database->query($query)->fetchColumn();
            $data = json_decode($state ?: '{}', true) ?: [];
            $result = $callback($data);
            if ($write) {
                $save = $this->database->prepare(
                    'INSERT INTO app_state (id, state) VALUES (1, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = UTC_TIMESTAMP()'
                );
                $save->execute([json_encode($data, JSON_UNESCAPED_SLASHES)]);
            }
            $this->database->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->database->rollBack();
            throw $e;
        }
    }
}
