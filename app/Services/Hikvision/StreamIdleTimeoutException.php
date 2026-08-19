<?php

namespace App\Services\Hikvision;

use RuntimeException;

/**
 * The device emits keepalives, so silence beyond the idle timeout
 * always means a half-open TCP connection, never a quiet device (§7's
 * stream worker notes). Thrown rather than returned so a caller can't
 * accidentally treat "went idle" the same as "stream ended cleanly" —
 * they need different exit codes (see hikvision:stream).
 */
class StreamIdleTimeoutException extends RuntimeException {}
