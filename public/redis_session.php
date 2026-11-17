<?php
/**
 * Session handler Redis via Upstash REST API.
 * Tidak membutuhkan ekstensi phpredis; hanya PHP cURL.
 */
final class MatrikulasiUpstashSessionHandler implements SessionHandlerInterface
{
    private string $url;
    private string $token;
    private string $prefix;
    private int $ttl;
    private int $lockTtl;
    private ?string $lockedId = null;
    private ?string $lockToken = null;

    public function __construct(
        string $url,
        string $token,
        string $prefix = 'matrikulasi:session:',
        int $ttl = 28800,
        int $lockTtl = 120
    ) {
        $this->url = rtrim($url, '/');
        $this->token = $token;
        $this->prefix = $prefix;
        $this->ttl = $ttl;
        $this->lockTtl = $lockTtl;
    }

    private function command(array $parts)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Ekstensi PHP cURL tidak tersedia untuk Redis session.');
        }

        $payload = json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new RuntimeException('Perintah Redis tidak dapat dikodekan sebagai JSON.');
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json'
            ),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Koneksi Redis gagal: ' . $curlError);
        }

        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new RuntimeException('Redis REST mengembalikan HTTP ' . $status . '.');
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException('Redis REST: ' . (string) $decoded['error']);
        }

        return $decoded['result'] ?? null;
    }

    private function acquireLock(string $id): bool
    {
        if ($this->lockedId === $id && $this->lockToken !== null) {
            return true;
        }

        $lockKey = $this->prefix . 'lock:' . $id;
        $token = bin2hex(random_bytes(24));

        // Menunggu sebentar jika request lain sedang mengubah session yang sama.
        for ($attempt = 0; $attempt < 35; $attempt++) {
            $result = $this->command(array(
                'SET', $lockKey, $token, 'NX', 'EX', $this->lockTtl
            ));

            if ($result === 'OK') {
                $this->lockedId = $id;
                $this->lockToken = $token;
                return true;
            }

            usleep(100000);
        }

        error_log('Matrikulasi: gagal memperoleh Redis session lock setelah 3,5 detik.');
        return false;
    }

    private function releaseLock(?string $id = null): void
    {
        if ($this->lockedId === null || $this->lockToken === null) {
            return;
        }
        if ($id !== null && $id !== $this->lockedId) {
            return;
        }

        $lockKey = $this->prefix . 'lock:' . $this->lockedId;
        $script = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";

        try {
            $this->command(array('EVAL', $script, '1', $lockKey, $this->lockToken));
        } catch (Throwable $e) {
            error_log('Matrikulasi: gagal melepas Redis session lock: ' . $e->getMessage());
        }

        $this->lockedId = null;
        $this->lockToken = null;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            if (!$this->acquireLock($id)) {
                return false;
            }

            $value = $this->command(array('GET', $this->prefix . $id));
            if ($value === null || $value === false || $value === '') {
                return '';
            }

            $decoded = base64_decode((string) $value, true);
            return $decoded === false ? '' : $decoded;
        } catch (Throwable $e) {
            error_log('Matrikulasi Redis session read: ' . $e->getMessage());
            return false;
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            if (!$this->acquireLock($id)) {
                return false;
            }

            // Base64 menjaga payload session PHP tetap aman untuk JSON REST.
            $value = base64_encode($data);
            $result = $this->command(array(
                'SET', $this->prefix . $id, $value, 'EX', $this->ttl
            ));

            return $result === 'OK';
        } catch (Throwable $e) {
            error_log('Matrikulasi Redis session write: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->command(array('DEL', $this->prefix . $id));
            $this->releaseLock($id);
            return true;
        } catch (Throwable $e) {
            error_log('Matrikulasi Redis session destroy: ' . $e->getMessage());
            $this->releaseLock($id);
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis menghapus session otomatis melalui EX TTL.
        return 0;
    }
}
