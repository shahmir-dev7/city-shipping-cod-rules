<?php
/**
 * Bootstrap for pure-logic unit tests (no WordPress needed).
 *
 * @package CityRules
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/includes/Normalizer.php';
require_once dirname( __DIR__, 2 ) . '/includes/Matcher.php';
require_once dirname( __DIR__, 2 ) . '/includes/Csv.php';