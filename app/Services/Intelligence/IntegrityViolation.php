<?php

namespace App\Services\Intelligence;

use RuntimeException;

/** A result referred to a resource or row outside the investigation's team. */
final class IntegrityViolation extends RuntimeException {}
