<?php

namespace App\AI;

use RuntimeException;

/** The AI's message does not follow protocol aiso.v1. The orchestrator asks once more, then gives a gentle error. */
class ProtocolException extends RuntimeException
{
}
