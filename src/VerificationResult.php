<?php

declare(strict_types=1);

namespace OPA;

class VerificationResult
{
    private function __construct(
        private bool $signed,
        private bool $valid,
        private ?string $error = null,
    ) {
    }

    /**
     * Archive is signed and all verification checks passed.
     */
    public static function success(): self
    {
        return new self(signed: true, valid: true);
    }

    /**
     * Archive is signed but verification failed.
     */
    public static function failure(string $error): self
    {
        return new self(signed: true, valid: false, error: $error);
    }

    /**
     * Archive is not signed (no SIGNATURE.SF present).
     */
    public static function unsigned(): self
    {
        return new self(signed: false, valid: false);
    }

    public function isSigned(): bool
    {
        return $this->signed;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
