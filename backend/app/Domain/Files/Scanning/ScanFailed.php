<?php

namespace App\Domain\Files\Scanning;

use RuntimeException;

/** The scanner couldn't give an answer; the file stays pending and the job retries. */
class ScanFailed extends RuntimeException {}
