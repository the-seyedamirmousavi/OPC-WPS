<?php

namespace App\Assignment;

use RuntimeException;

/** The LLM strategy could not produce a usable answer (no key, API failure, refusal, unparsable output). */
class LlmUnavailableException extends RuntimeException
{
}
