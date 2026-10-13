<?php

namespace App\Assignment;

use RuntimeException;

/** The LLM strategy could not produce a usable answer (no key, API failure, refusal, unparsable output). */
class LlmUnavailableException extends RuntimeException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
