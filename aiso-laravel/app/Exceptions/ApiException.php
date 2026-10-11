<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An error with an HTTP status and a message that is safe to show. The message is already in the language of the
 * request (see LanguageService::forRequest).
 */
class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, $message);
    }
}
