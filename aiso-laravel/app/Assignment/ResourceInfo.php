<?php

namespace App\Assignment;

/** @param int $used operations currently ASSIGNED or IN_PROGRESS on this resource */
final class ResourceInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $capacity,
        public readonly int $used,
        public readonly ?string $responsibleUserId,
        public readonly bool $active,
    ) {
    }
}
