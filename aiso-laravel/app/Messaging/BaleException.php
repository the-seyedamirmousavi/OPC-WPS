<?php

namespace App\Messaging;

use RuntimeException;

/** A failed call to the Bale bot API (network error, HTTP error, or "ok": false). The message never contains the token. */
class BaleException extends RuntimeException
{
}
