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
function entity_metrics_post_update_deduplicate_media_downloads(&$sandbox = []) {
  $database = \Drupal::database();
  $lock = \Drupal::lock();
  if (!$lock->acquire('entity_metrics.geolocation', 300)) {
    throw new RuntimeException('Geolocation or download cleanup is already running. Retry the update when it finishes.');
  }
  try {
    $query = $database->select('entity_metrics_data', 'd')
      ->condition('entity_type', 'media')->isNotNull('region_id');
    if (!isset($sandbox['max_id'])) {
      $maximum = clone $query;
      $maximum->addExpression('MAX(id)');
      $sandbox = [
        'max_id' => (int) $maximum->execute()->fetchField(),
        'processed' => 0,
        'deleted' => 0,
        'last' => NULL,
        'first' => NULL,
        'first_deleted' => FALSE,
      ];
      $sandbox['total'] = (int) (clone $query)->condition('id', $sandbox['max_id'], '<=')->countQuery()->execute()->fetchField();
    }
    $query->condition('id', $sandbox['max_id'], '<=');
    $fields = ['entity_id', 'region_id', 'timestamp', 'id'];
    // Continue after the last scanned row, even when that row was deleted.
    if ($sandbox['last'] !== NULL) {
      $after = $query->orConditionGroup();
      foreach ($fields as $index => $field) {
        $clause = $query->andConditionGroup();
        foreach (array_slice($fields, 0, $index) as $previous) {
          $clause->condition($previous, $sandbox['last'][$previous]);
        }
        $after->condition($clause->condition($field, $sandbox['last'][$field], '>'));
      }
      $query->condition($after);
    }
    foreach ($fields as $field) {
      $query->orderBy($field);
    }
    $transaction = $database->startTransaction();
    $events = $query->fields('d', $fields)->range(0, 500)->forUpdate()->execute()->fetchAll();
    $next = $sandbox;
    $ids = [];
    foreach ($events as $event) {
      $event = array_map('intval', (array) $event);
      $first = $next['first'];
      // Anchor to the first hit, even after a previous batch deleted it.
      if ($first === NULL || $event['entity_id'] !== $first['entity_id']
        || $event['region_id'] !== $first['region_id']
        || $event['timestamp'] - $first['timestamp'] > 3600) {
        $next['first'] = $event;
        $next['first_deleted'] = FALSE;
      }
      else {
        // Once a group repeats, remove its first hit as well as every repeat.
        if (!$next['first_deleted']) {
          $ids[] = $first['id'];
          $next['first_deleted'] = TRUE;
        }
        $ids[] = $event['id'];
      }
      $next['last'] = $event;
    }
    if ($ids && $database->delete('entity_metrics_data')->condition('id', $ids, 'IN')->execute() !== count($ids)) {
      throw new RuntimeException('Candidate events changed; cleanup batch rolled back.');
    }
    unset($transaction);
    $next['processed'] += count($events);
    $next['deleted'] += count($ids);
    $next['#finished'] = count($events) < 500 ? 1 : min(0.99, $next['processed'] / max(1, $next['total']));
    $sandbox = $next;
    if ($ids) {
      Cache::invalidateTags(['entity_metrics_regions']);
    }
    return t('Removed @count suspected viewer download events.', ['@count' => $sandbox['deleted']]);
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
