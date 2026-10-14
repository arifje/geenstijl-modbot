<?php

namespace App\Services\Analysis;

/**
 * The model's answer was cut off at the max output tokens. The analyser
 * retries with smaller batches.
 */
class LlmOutputTooLong extends LlmException {}
