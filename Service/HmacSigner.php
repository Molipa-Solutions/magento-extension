<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Service;

final class HmacSigner
{
    public function signCanonicalWithBodyHashBase64(array $parts, string $bodyJson, string $secret): string
    {
        $bodySha256Hex = hash('sha256', $bodyJson);

        $canonicalParts = array_merge($parts, [$bodySha256Hex]);
        $canonical = implode("\n", $canonicalParts);

        $sigRaw = hash_hmac('sha256', $canonical, $secret, true);

        return base64_encode($sigRaw);
    }
}
