<?php

namespace App\Support;

class SensitiveDataMasker
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function maskArray(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            $keyText = strtolower((string) $key);
            if (self::isSensitiveKey($keyText)) {
                $result[$key] = self::maskScalar($value);
                continue;
            }

            if (is_array($value)) {
                $result[$key] = self::maskArray($value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    public static function clipPayload(mixed $payload, int $maxBytes, string $label): mixed
    {
        $max = max(256, $maxBytes);
        $encoded = self::encodePayload($payload);
        $size = strlen($encoded);
        if ($size <= $max) {
            return $payload;
        }

        return [
            'truncated' => true,
            'label' => $label,
            'original_size_bytes' => $size,
            'max_bytes' => $max,
            'sha256' => hash('sha256', $encoded),
            'preview' => mb_substr($encoded, 0, 512),
        ];
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach ([
            'secret',
            'token',
            'password',
            'signature',
            'authorization',
            'app_key',
            'client_secret',
            'access_key',
            'phone',
        ] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function maskScalar(mixed $value): string
    {
        if ($value === null) {
            return '***';
        }

        $text = (string) $value;
        $length = strlen($text);
        if ($length <= 4) {
            return str_repeat('*', $length > 0 ? $length : 3);
        }

        return substr($text, 0, 2).str_repeat('*', max(3, $length - 4)).substr($text, -2);
    }

    private static function encodePayload(mixed $payload): string
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json)) {
                return $json;
            }
        } catch (\Throwable) {
            // fallback to string cast
        }

        return (string) $payload;
    }
}
