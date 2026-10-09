<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * A provider call that cannot say who it is for or what it does. Thrown at the provider boundary,
 * before any money is spent — it is a defect in the calling code path, never a customer state.
 */
class AiCallContextException extends RuntimeException {}
