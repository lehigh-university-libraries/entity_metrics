<?php

namespace Drupal\entity_metrics;

use MaxMind\Db\Reader\InvalidDatabaseException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use MaxMind\Db\Reader;

/**
 * Enriches bounded batches using a local City MMDB, without remote IP lookups.
 */
class GeolocationBackfill {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
  ) {}

  /**
   * Opens a real local file; missing/corrupt databases never consume events.
   */
  protected function openReader(): Reader {
    $uri = $this->configFactory->get('entity_metrics.settings')->get('geolocation_database');
    if (!is_string($uri) || (!str_starts_with($uri, 'private://') && !str_starts_with($uri, '/'))) {
      throw new \RuntimeException('Configure a private:// or absolute local City MMDB path.');
    }
    $path = $this->fileSystem->realpath($uri);
    if (!$path || !is_file($path) || !is_readable($path) || !stream_is_local($path)) {
      throw new \RuntimeException('The local City database is missing or unreadable. Download it before backfilling.');
    }
    $reader = new Reader($path);
    if (!str_contains($reader->metadata()->databaseType, 'City')) {
      $reader->close();
      throw new \RuntimeException('A City MMDB is required for country, region, city, and coordinates.');
    }
    return $reader;
  }

  /**
   * Normalizes City records, leaving absent coordinates NULL.
   */
  public static function location(?array $record): ?array {
    $country = $record['country']['iso_code'] ?? '';
    if (!is_string($country) || !preg_match('/^[A-Z]{2}$/D', $country)) {
      return NULL;
    }
    $latitude = $record['location']['latitude'] ?? NULL;
    $longitude = $record['location']['longitude'] ?? NULL;
    if (!is_numeric($latitude) || !is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
      $latitude = $longitude = NULL;
    }
    return [
      'country' => $country,
      'region' => mb_substr((string) ($record['subdivisions'][0]['names']['en'] ?? ''), 0, 255),
      'city' => mb_substr((string) ($record['city']['names']['en'] ?? ''), 0, 255),
      'latitude' => $latitude === NULL ? NULL : number_format((float) $latitude, 8, '.', ''),
      'longitude' => $longitude === NULL ? NULL : number_format((float) $longitude, 8, '.', ''),
    ];
  }

  /**
   * Uses an indexed fingerprint and reuses matching API regions.
   */
  protected function region(array $location): int {
    $key = hash('sha256', json_encode($location, JSON_THROW_ON_ERROR));
    $id = $this->database->select('entity_metrics_regions', 'r')->fields('r', ['id'])
      ->condition('location_key', $key)->execute()->fetchField();
    if ($id) {
      return (int) $id;
    }
    $query = $this->database->select('entity_metrics_regions', 'r')->fields('r', ['id']);
    foreach ($location as $field => $value) {
      $value === NULL ? $query->isNull($field) : $query->condition($field, $value);
    }
    $id = $query->range(0, 1)->execute()->fetchField();
    if ($id) {
      $this->database->update('entity_metrics_regions')->fields(['location_key' => $key])->condition('id', $id)->execute();
      return (int) $id;
    }
    return (int) $this->database->insert('entity_metrics_regions')->fields($location + ['location_key' => $key])->execute();
  }

  /**
   * Commits a batch, saving row status for both matches and misses.
   */
  public function process(int $limit = 500): array {
    if ($limit < 1 || $limit > 10000) {
      throw new \InvalidArgumentException('Batch size must be between 1 and 10000.');
    }
    if (!$this->lock->acquire('entity_metrics.geolocation', 300)) {
      throw new \RuntimeException('Another geolocation batch is running.');
    }
    $reader = NULL;
    try {
      $reader = $this->openReader();
      $transaction = $this->database->startTransaction();
      $query = $this->database->select('entity_metrics_data', 'd');
      $query->fields('d', ['id', 'ip_address', 'region_id']);
      $query->leftJoin('entity_metrics_regions', 'r', 'r.id = d.region_id');
      $query->addField('r', 'country');
      // Retain recent IPs for the recording endpoint's rolling flood check.
      $query->condition('d.timestamp', $this->time->getCurrentTime() - 60, '<=');
      $query->condition('d.geolocation_status', 0)->orderBy('d.id')->range(0, $limit);
      $result = ['processed' => 0, 'located' => 0, 'unknown' => 0, 'last_id' => 0];
      $lookups = [];
      foreach ($query->execute() as $event) {
        $ip = $event->ip_address;
        $public_ip = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        $region = NULL;
        if (is_string($event->country) && preg_match('/^[A-Z]{2}$/D', $event->country)) {
          $region = (int) $event->region_id;
        }
        elseif ($public_ip) {
          if (!array_key_exists($ip, $lookups)) {
            $location = self::location($reader->get($ip));
            $lookups[$ip] = $location === NULL ? NULL : $this->region($location);
          }
          $region = $lookups[$ip];
        }
        $this->database->update('entity_metrics_data')->fields([
          'region_id' => $region,
          'geolocation_status' => $region === NULL ? 2 : 1,
          // Keep unresolved public IPs for an explicit retry with a newer DB.
          'ip_address' => $region !== NULL || !$public_ip ? NULL : $ip,
        ])->condition('id', $event->id)->execute();
        $result['processed']++;
        $result[$region === NULL ? 'unknown' : 'located']++;
        $result['last_id'] = (int) $event->id;
      }
      unset($transaction);
      if ($result['processed']) {
        Cache::invalidateTags(['entity_metrics_regions']);
      }
      return $result;
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      // Reader errors can contain the lookup IP; do not leak it into CLI/logs.
      if ($exception instanceof InvalidDatabaseException) {
        throw new \RuntimeException('The local City database is invalid; this batch was rolled back.');
      }
      throw $exception;
    }
    finally {
      if ($reader !== NULL) {
        $reader->close();
      }
      $this->lock->release('entity_metrics.geolocation');
    }
  }

  /**
   * Explicitly retries misses that still have a usable source address.
   */
  public function retryUnknown(): int {
    if (!$this->lock->acquire('entity_metrics.geolocation', 300)) {
      throw new \RuntimeException('Another geolocation batch is running.');
    }
    try {
      return $this->database->update('entity_metrics_data')->fields(['geolocation_status' => 0])
        ->condition('geolocation_status', 2)->isNotNull('ip_address')->execute();
    }
    finally {
      $this->lock->release('entity_metrics.geolocation');
    }
  }

}
