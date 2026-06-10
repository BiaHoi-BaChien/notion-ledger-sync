<?php

namespace App\Services\WebAuthn;

use App\Services\WebAuthn\Exceptions\RegistrationValidationException;

final class CborDecoder
{
    private const MAX_DEPTH = 16;

    /**
     * @return array{value:mixed,consumed:int}
     */
    public function decode(string $data): array
    {
        $offset = 0;
        $value = $this->decodeValue($data, $offset, 0);

        return ['value' => $value, 'consumed' => $offset];
    }

    private function decodeValue(string $data, int &$offset, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH || $offset >= strlen($data)) {
            throw new RegistrationValidationException('パスキーの attestation データが不正です。');
        }

        $initial = ord($data[$offset++]);
        $majorType = $initial >> 5;
        $additionalInfo = $initial & 0x1F;

        if ($additionalInfo === 31) {
            throw new RegistrationValidationException('サポートされていない CBOR 形式です。');
        }

        $length = $this->decodeLength($data, $offset, $additionalInfo);

        return match ($majorType) {
            0 => $length,
            1 => -1 - $length,
            2, 3 => $this->readBytes($data, $offset, $length),
            4 => $this->decodeArray($data, $offset, $length, $depth + 1),
            5 => $this->decodeMap($data, $offset, $length, $depth + 1),
            7 => $this->decodeSimpleValue($additionalInfo),
            default => throw new RegistrationValidationException('サポートされていない CBOR 形式です。'),
        };
    }

    private function decodeLength(string $data, int &$offset, int $additionalInfo): int
    {
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }

        $byteLength = match ($additionalInfo) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new RegistrationValidationException('パスキーの attestation データが不正です。'),
        };

        $bytes = $this->readBytes($data, $offset, $byteLength);
        $value = match ($byteLength) {
            1 => ord($bytes),
            2 => unpack('n', $bytes)[1],
            4 => unpack('N', $bytes)[1],
            8 => unpack('J', $bytes)[1],
        };

        if (! is_int($value) || $value < 0) {
            throw new RegistrationValidationException('パスキーの attestation データが大きすぎます。');
        }

        return $value;
    }

    private function readBytes(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $length > strlen($data) - $offset) {
            throw new RegistrationValidationException('パスキーの attestation データが不正です。');
        }

        $value = substr($data, $offset, $length);
        $offset += $length;

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function decodeArray(string $data, int &$offset, int $length, int $depth): array
    {
        $result = [];

        for ($index = 0; $index < $length; $index++) {
            $result[] = $this->decodeValue($data, $offset, $depth);
        }

        return $result;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeMap(string $data, int &$offset, int $length, int $depth): array
    {
        $result = [];
        $seen = [];

        for ($index = 0; $index < $length; $index++) {
            $key = $this->decodeValue($data, $offset, $depth);

            if (! is_int($key) && ! is_string($key)) {
                throw new RegistrationValidationException('パスキーの attestation データが不正です。');
            }

            $identity = is_int($key) ? 'i:'.$key : 's:'.$key;
            if (isset($seen[$identity])) {
                throw new RegistrationValidationException('パスキーの attestation データが不正です。');
            }

            $seen[$identity] = true;
            $result[$key] = $this->decodeValue($data, $offset, $depth);
        }

        return $result;
    }

    private function decodeSimpleValue(int $additionalInfo): ?bool
    {
        return match ($additionalInfo) {
            20 => false,
            21 => true,
            22 => null,
            default => throw new RegistrationValidationException('サポートされていない CBOR 形式です。'),
        };
    }
}
