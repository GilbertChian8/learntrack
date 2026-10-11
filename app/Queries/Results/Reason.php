<?php

namespace App\Queries\Results;

/**
 * Why a learner is behind (api-contract.md section 6).
 */
interface Reason
{
    public function text(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
