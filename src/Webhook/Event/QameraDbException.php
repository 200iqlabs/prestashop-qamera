<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

use RuntimeException;

/**
 * Wraps a `Db::execute()` returning false so the event-dispatch layer can
 * distinguish "row not found" (legitimate, just a WARNING) from "the DB
 * call itself failed" (genuine error, logged at error level with the
 * exception class name only — never the SQL).
 */
final class QameraDbException extends RuntimeException
{
}
