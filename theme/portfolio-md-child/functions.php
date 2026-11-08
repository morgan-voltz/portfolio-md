<?php

declare(strict_types=1);

/**
 * Portfolio MD, child theme of GeneratePress (ADR-022, lot 5 of ADR-020).
 *
 * The theme renders what the portfolio-md plugin stores: it never decides
 * the language of a document and never writes anything. Every call to the
 * plugin or to Polylang is guarded by function_exists(), so that the theme
 * still renders when either is inactive.
 *
 * Lot 5a: the skeleton only. GeneratePress enqueues style.css itself. The
 * language switcher, the list with fallback and the FR badge come with the
 * next sub-lots.
 */
