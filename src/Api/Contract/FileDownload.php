<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * What a claim answers with: the link the viewer asked for, the drive's own
 * extraction code when the list has one (useless without the link, so it
 * travels with it), what was charged, and the balance the charge left behind.
 *
 * `balance` is null when nothing was charged — a free list — since there is
 * no new balance to report. A waived staff delivery (CreditSettings' waiver
 * level) still reports the holder's unchanged balance, like any paid claim.
 */
final class FileDownload
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $code,
        public readonly int $price,
        public readonly ?int $balance,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'code' => $this->code,
            'price' => $this->price,
            'balance' => $this->balance,
        ];
    }
}
