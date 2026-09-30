<?php

namespace Drupal\entity_metrics;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Retains counts and map locations before deleting old, geolocated events.
 */
class MetricsRollup {

  public function __construct(
    protected Connection $database,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
  ) {}

  /**
   * Moves bounded batches atomically; failures retain all source rows.
   */
  public function process(int $limit = 500): array {
    if ($limit < 1 || $limit > 10000) {
      throw new \InvalidArgumentException('Batch size must be between 1 and 10000.');
    }
    // Share the enrichment lock so region assignments cannot change mid-batch.
    if (!$this->lock->acquire('entity_metrics.geolocation', 300)) {
      throw new \RuntimeException('Geolocation or a metrics rollup is already running.');
    }
    try {
      $now = $this->time->getCurrentTime();
      // Only complete months older than a year lose their daily granularity.
      $month_cutoff = (new \DateTimeImmutable('@' . $now))
        ->modify('first day of this month')->setTime(0, 0)->modify('-1 year')->getTimestamp();
      $transaction = $this->database->startTransaction();
      $query = $this->database->select('entity_metrics_data', 'd');
      $query->innerJoin('entity_metrics_regions', 'r', 'r.id = d.region_id');
      $events = $query->fields('d')
        ->condition('d.timestamp', $now - 31 * 86400, '<')
        ->condition('d.geolocation_status', 1)->isNull('d.ip_address')
        ->orderBy('d.id')->range(0, $limit)->forUpdate()->execute()->fetchAllAssoc('id');
      $counts = $locations = [];
      foreach ($events as $event) {
        $monthly = $event->timestamp < $month_cutoff;
        $period = strtotime(gmdate($monthly ? 'Y-m-01' : 'Y-m-d', $event->timestamp) . ' UTC');
        $key = [
          $event->entity_type, (int) $event->entity_id, (int) $event->cookie_set,
          $monthly ? 'month' : 'day', $period,
        ];
        $index = serialize($key);
        $counts[$index] ??= array_combine(['entity_type', 'entity_id', 'cookie_set', 'granularity', 'period'], $key) + ['event_count' => 0];
        $counts[$index]['event_count']++;
        $key = [$event->entity_type, (int) $event->entity_id, (int) $event->region_id];
        $index = serialize($key);
        $locations[$index] ??= array_combine(['entity_type', 'entity_id', 'region_id'], $key) + [
          'event_count' => 0,
          'last_timestamp' => 0,
        ];
        $locations[$index]['event_count']++;
        $locations[$index]['last_timestamp'] = max($locations[$index]['last_timestamp'], (int) $event->timestamp);
      }
      $expected = [];
      foreach (['entity_metrics_counts' => $counts, 'entity_metrics_map' => $locations] as $table => $groups) {
        $expected[$table] = [];
        foreach ($groups as $group) {
          $expected[$table][] = $this->retain($table, $group);
        }
      }
      foreach ($expected as $table => $rows) {
        $this->verify($table, $rows);
      }
      if ($events) {
        $deleted = $this->database->delete('entity_metrics_data')->condition('id', array_keys($events), 'IN')->execute();
        if ($deleted !== count($events)) {
          throw new \RuntimeException('The raw event batch changed; rollup cancelled.');
        }
      }

      $days = $this->database->select('entity_metrics_counts', 'c')->fields('c')
        ->condition('granularity', 'day')->condition('period', $month_cutoff, '<')
        ->orderBy('period')->range(0, $limit)->forUpdate()->execute()->fetchAll();
      foreach ($days as $day) {
        $month = (array) $day;
        $month['granularity'] = 'month';
        $month['period'] = strtotime(gmdate('Y-m-01', $day->period) . ' UTC');
        $this->verify('entity_metrics_counts', [$this->retain('entity_metrics_counts', $month)]);
        $delete = $this->database->delete('entity_metrics_counts');
        foreach ((array) $day as $field => $value) {
          $delete->condition($field, $value);
        }
        if ($delete->execute() !== 1) {
          throw new \RuntimeException('The daily count changed; rollup cancelled.');
        }
      }
      unset($transaction);
      if ($events || $days) {
        Cache::invalidateTags(['entity_metrics_regions']);
      }
      return ['processed' => count($events), 'compacted' => count($days)];
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $exception;
    }
    finally {
      $this->lock->release('entity_metrics.geolocation');
    }
  }

  /**
   * Adds to a locked summary and returns the exact expected stored values.
   */
  protected function retain(string $table, array $group): array {
    $key = array_diff_key($group, array_flip(['event_count', 'last_timestamp']));
    $query = $this->database->select($table, 's')->fields('s')->forUpdate();
    foreach ($key as $field => $value) {
      $query->condition($field, $value);
    }
    $existing = $query->execute()->fetchAssoc();
    $values = ['event_count' => (int) ($existing['event_count'] ?? 0) + (int) $group['event_count']];
    if (isset($group['last_timestamp'])) {
      $values['last_timestamp'] = max((int) ($existing['last_timestamp'] ?? 0), (int) $group['last_timestamp']);
    }
    $this->database->merge($table)->keys($key)->fields($values)->execute();
    return $key + $values;
  }

  /**
   * Reads summaries back before allowing their source rows to be deleted.
   */
  private function verify(string $table, array $expected): void {
    foreach ($expected as $row) {
      $query = $this->database->select($table, 's');
      foreach ($row as $field => $value) {
        $query->condition($field, $value);
      }
      if ((int) $query->countQuery()->execute()->fetchField() !== 1) {
        throw new \RuntimeException('Retained metrics did not match the source batch; rollup cancelled.');
      }
    }
  }

}
