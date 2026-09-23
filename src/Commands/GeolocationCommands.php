<?php

namespace Drupal\entity_metrics\Commands;

use Drupal\entity_metrics\GeolocationBackfill;
use Drupal\geoip_autoupdate\GeoIpUpdaterService;
use Drush\Commands\DrushCommands;

/**
 * Performs local enrichment outside the web request timeout.
 */
class GeolocationCommands extends DrushCommands {

  public function __construct(
    protected GeolocationBackfill $backfill,
    protected GeoIpUpdaterService $updater,
  ) {
    parent::__construct();
  }

  /**
   * Backfills eligible events; interrupted runs resume automatically.
   *
   * @command entity-metrics:geolocate
   * @option batch-size Events per transaction, 1–10000.
   * @option retry-unknown Retry unresolved public addresses using the current DB.
   */
  public function geolocate(array $options = ['batch-size' => 500, 'retry-unknown' => FALSE]): void {
    $size = filter_var($options['batch-size'], FILTER_VALIDATE_INT, [
      'options' => ['min_range' => 1, 'max_range' => 10000],
    ]);
    if ($size === FALSE) {
      throw new \InvalidArgumentException('Batch size must be between 1 and 10000.');
    }
    if ($options['retry-unknown']) {
      $this->backfill->retryUnknown();
    }
    do {
      $result = $this->backfill->process($size);
      if ($result['processed']) {
        $this->logger()->notice(sprintf('Through event ID %d: %d processed, %d located, %d unknown.', $result['last_id'], $result['processed'], $result['located'], $result['unknown']));
      }
    } while ($result['processed'] === $size);
    $this->logger()->success('Eligible events processed. Events from the last minute wait for the next run. Rebuild downstream aggregate reports after a historical backfill.');
  }

  /**
   * Downloads the configured GeoLite2 edition using GeoIP Auto-Update.
   *
   * @command entity-metrics:geoip-update
   */
  public function update(): void {
    $this->updater->forceUpdate();
    $this->logger()->success('Local geolocation database updated.');
  }

}
