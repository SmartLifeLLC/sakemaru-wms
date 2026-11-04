<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class DistributionExcelHeaderMissingException extends RuntimeException implements ShouldntReport {}
