<?php

namespace App\Services\ChannelHub\Mapping;

use Illuminate\Support\Arr;

class RawPayloadParser
{
    public function decode(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function extractRecords(array $payload): array
    {
        $records = Arr::get($payload, 'raw_payload.records');
        if (! is_array($records)) {
            $records = Arr::get($payload, 'response.raw_payload.records');
        }
        if (! is_array($records)) {
            $records = Arr::get($payload, 'records');
        }
        if (! is_array($records)) {
            return [];
        }

        return array_values(
            array_filter($records, static fn (mixed $item): bool => is_array($item))
        );
    }
}
