<?php

/**
 * @file
 * Hooks provided by Entity Metrics.
 */

/**
 * Checks a page view after validation and access checks, before recording it.
 *
 * @param string $ip
 *   The validated client IP address.
 *
 * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
 *   To reject the request without inserting an event.
 */
function hook_entity_metrics_visit_presave(string $ip): void {
  // Throw an HTTP exception here to prevent the page view from being recorded.
}
