<?php

namespace App\Services\Incidents;

use RuntimeException;

/** Someone else saved the incident's learning since it was loaded. */
class StaleLearningException extends RuntimeException {}
