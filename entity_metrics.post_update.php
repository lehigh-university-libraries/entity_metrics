<?php

/**
 * @file
 * Post-update functions for Entity Metrics.
 */

use Drupal\Core\Cache\Cache;

/**
 * Removes the IP index unless the optional rate limiter owns it.
 */
function entity_metrics_post_update_remove_unused_ip_index(): void {
  $schema = \Drupal::database()->schema();
  if (!\Drupal::moduleHandler()->moduleExists('entity_metrics_ratelimiter')
    && $schema->indexExists('entity_metrics_data', 'ip_timestamp')) {
    $schema->dropIndex('entity_metrics_data', 'ip_timestamp');
  }
}

/**
 * Removes suspected viewer download groups within an hour of their first hit.
 */
function entity_metrics_post_update_deduplicate_media_downloads() {
  $database = \Drupal::database();
  $lock = \Drupal::lock();
  if (!$lock->acquire('entity_metrics.geolocation', 3600)) {
    throw new RuntimeException('Geolocation or download cleanup is already running. Retry the update when it finishes.');
  }
  try {
    $transaction = $database->startTransaction();
    // Avoid repeating the sorted source query for every 500 events.
    $events = $database->select('entity_metrics_data', 'd')
      ->fields('d', ['entity_id', 'region_id', 'timestamp', 'id'])
      ->condition('entity_type', 'media')->isNotNull('region_id')
      ->orderBy('entity_id')->orderBy('region_id')->orderBy('timestamp')->orderBy('id')
      ->forUpdate()->execute()->fetchAll();
    $first = NULL;
    $first_deleted = FALSE;
    $ids = [];
    foreach ($events as $event) {
      // Repeats do not move the window beyond an hour after its first hit.
      if ($first === NULL || $event->entity_id !== $first->entity_id
        || $event->region_id !== $first->region_id
        || $event->timestamp - $first->timestamp > 3600) {
        $first = $event;
        $first_deleted = FALSE;
      }
      else {
        // Once a group repeats, remove its first hit as well as every repeat.
        if (!$first_deleted) {
          $ids[] = $first->id;
          $first_deleted = TRUE;
        }
        $ids[] = $event->id;
      }
    }
    unset($events);
    // Bound SQL parameter lists while committing the entire cleanup atomically.
    foreach (array_chunk($ids, 500) as $batch) {
      if ($database->delete('entity_metrics_data')->condition('id', $batch, 'IN')->execute() !== count($batch)) {
        throw new RuntimeException('Candidate events changed; cleanup rolled back.');
      }
    }
    unset($transaction);
    if ($ids) {
      Cache::invalidateTags(['entity_metrics_regions']);
    }
    return t('Removed @count suspected viewer download events.', ['@count' => count($ids)]);
  }
  catch (Throwable $exception) {
    if (isset($transaction)) {
      $transaction->rollBack();
    }
    throw $exception;
  }
  finally {
    $lock->release('entity_metrics.geolocation');
  }
}
