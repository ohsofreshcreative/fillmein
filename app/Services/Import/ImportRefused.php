<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Odmowa wykonania przed jakimkolwiek zapisem. Polecenia kończą się wtedy kodem 4, jak `tests/bin/wp.sh`.
 */
class ImportRefused extends RuntimeException
{
}
