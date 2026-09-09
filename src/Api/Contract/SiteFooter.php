<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Compliance strings for the front-end footer: ICP filing, public-security
 * filing (plus its digits-only code for the police search link) and a
 * free-form note. Empty strings mean "do not render"; link targets are a
 * front-end concern.
 */
final class SiteFooter
{
    public function __construct(
        public readonly string $icp,
        public readonly string $mps,
        public readonly string $mpsCode,
        public readonly string $note,
    ) {
    }

    /** @return array{icp: string, mps: string, mpsCode: string, note: string} */
    public function toArray(): array
    {
        return [
            'icp' => $this->icp,
            'mps' => $this->mps,
            'mpsCode' => $this->mpsCode,
            'note' => $this->note,
        ];
    }
}
