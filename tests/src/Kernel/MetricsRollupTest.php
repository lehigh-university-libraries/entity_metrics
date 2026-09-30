<?php

declare(strict_types=1);

namespace Drupal\Tests\entity_metrics\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\entity_metrics\Controller\VisitController;
use Drupal\entity_metrics\MetricsRollup;
use Drupal\entity_metrics\Plugin\Block\MapBlock;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies retained history, report reads, and atomic deletion with real SQL.
 */
#[Group('entity_metrics')]
#[RunTestsInSeparateProcesses]
class MetricsRollupTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'node', 'text', 'entity_metrics'];

  /**
   * Fixed UTC clock for retention boundaries.
   */
  private int $now;

  /**
   * A viewable node.
   */
  private int $nodeId;

  /**
   * A known location.
   */
  private int $regionId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'entity_metrics']);
    $this->container->get('module_handler')->loadInclude('entity_metrics', 'install');
    entity_metrics_install();
    Role::create(['id' => 'anonymous', 'label' => 'Anonymous'])->grantPermission('access content')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $node = Node::create(['type' => 'page', 'title' => 'Public', 'status' => 1]);
    $node->save();
    $this->nodeId = (int) $node->id();
    $this->regionId = (int) $this->container->get('database')->insert('entity_metrics_regions')->fields([
      'country' => 'GB',
      'region' => 'England',
      'city' => 'Boxford',
      'latitude' => 51.75,
      'longitude' => -1.25,
    ])->execute();
    $this->now = strtotime('2026-09-25 12:00:00 UTC');
    $this->setTime($this->now);
  }

  /**
   * Batches preserve counts, staff filtering, locations, and recent raw data.
   */
  public function testReportsAndResume(): void {
    $db = $this->container->get('database');
    $service = $this->container->get('entity_metrics.rollup');
    $this->event();
    $this->event(['timestamp' => $this->now - 50 * 86400]);
    $this->event(['cookie_set' => 1]);
    $this->event(['entity_type' => 'media']);
    $archive_region = (int) $db->insert('entity_metrics_regions')->fields([
      'country' => 'JP',
      'city' => 'Tokyo',
      'latitude' => 35.68,
      'longitude' => 139.69,
    ])->execute();
    $this->event(['timestamp' => strtotime('2024-12-31 23:59:59 UTC'), 'region_id' => $archive_region]);
    $draft = Node::create(['type' => 'page', 'title' => 'Private', 'status' => 0]);
    $draft->save();
    $this->event(['entity_id' => (int) $draft->id()]);
    // These rows must never be consumed by this batch.
    $retained = [
      $this->event(['timestamp' => $this->now - 31 * 86400]),
      $this->event(['timestamp' => $this->now - 30 * 86400]),
      $this->event(['timestamp' => $this->now - 30 * 86400 + 1]),
      $this->event(['geolocation_status' => 0]),
      $this->event(['geolocation_status' => 2, 'region_id' => NULL, 'ip_address' => '8.8.8.8']),
      $this->event(['region_id' => 99999]),
      $this->event(['ip_address' => '2.125.160.216']),
    ];
    $controller = VisitController::create($this->container);
    $before = json_decode($controller->getVisits('node', $this->nodeId)->getContent(), TRUE);
    $this->assertSame(['total' => 10, 'monthly' => 1], $before);
    $this->assertSame(2, $service->process(2)['processed']);
    $this->assertSame($before, json_decode($controller->getVisits('node', $this->nodeId)->getContent(), TRUE));
    $this->assertSame(4, $service->process(20)['processed']);
    $this->assertSame(['processed' => 0, 'compacted' => 0], $service->process());
    $this->assertSame($before, json_decode($controller->getVisits('node', $this->nodeId)->getContent(), TRUE));
    $this->assertEquals($retained, $db->select('entity_metrics_data', 'd')->fields('d', ['id'])->orderBy('id')->execute()->fetchCol());
    $this->assertSame(6, $this->summaryCount('entity_metrics_counts'));
    $this->assertSame(6, $this->summaryCount('entity_metrics_map'));
    $this->assertSame(4, (int) $db->select('entity_metrics_map')->countQuery()->execute()->fetchField());

    // The map still uses collection membership and checks each node's access.
    $db->schema()->createTable('node__field_member_of', [
      'fields' => [
        'entity_id' => ['type' => 'int', 'not null' => TRUE],
        'field_member_of_target_id' => ['type' => 'int', 'not null' => TRUE],
      ],
    ]);
    foreach ([$this->nodeId, (int) $draft->id()] as $id) {
      $db->insert('node__field_member_of')->fields(['entity_id' => $id, 'field_member_of_target_id' => $this->nodeId])->execute();
    }
    $route = $this->createMock(RouteMatchInterface::class);
    $route->method('getParameter')->with('node')->willReturn(Node::load($this->nodeId));
    $block = new MapBlock([], 'entity_metrics_map', ['provider' => 'entity_metrics'], $route, $db, $this->container->get('entity_type.manager'));
    $build = $block->build();
    $points = $build['#attached']['drupalSettings']['entityMetrics'];
    $this->assertNotEmpty($points);
    $this->assertContains('Tokyo', array_column($points, 'city'));
    foreach ($points as $point) {
      $this->assertSame('Public', $point['label']);
      $this->assertContains($point['city'], ['Boxford', 'Tokyo']);
      $this->assertEquals($point['city'] === 'Boxford' ? 51.75 : 35.68, $point['latitude']);
    }
    // Remove membership: retained locations cannot leak into other collections.
    $db->delete('node__field_member_of')->execute();
    $this->assertArrayNotHasKey('drupalSettings', $block->build()['#attached']);
  }

  /**
   * Failed verification rolls back both summaries and all raw deletions.
   */
  public function testVerificationFailurePreservesSources(): void {
    $db = $this->container->get('database');
    $this->event();
    $this->container->get('entity_metrics.rollup')->process();
    $id = $this->event();
    $broken = new class($db, $this->container->get('lock'), $this->container->get('datetime.time')) extends MetricsRollup {

      /**
       * Simulates a successful write that retained an incorrect map count.
       */
      protected function retain(string $table, array $group): array {
        $expected = parent::retain($table, $group);
        if ($table === 'entity_metrics_map') {
          $this->database->update($table)->fields(['event_count' => 999])->execute();
        }
        return $expected;
      }

    };
    try {
      $broken->process();
      $this->fail('An incorrect retained count was accepted.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('did not match', $exception->getMessage());
    }
    $this->assertSame(1, $this->summaryCount('entity_metrics_counts'));
    $this->assertSame(1, $this->summaryCount('entity_metrics_map'));
    $this->assertSame($id, (int) $db->select('entity_metrics_data', 'd')->fields('d', ['id'])->execute()->fetchField());
    $this->assertSame(1, $this->container->get('entity_metrics.rollup')->process()['processed']);
    $this->assertSame(2, $this->summaryCount('entity_metrics_counts'));
    $this->assertSame(2, $this->summaryCount('entity_metrics_map'));
  }

  /**
   * Old daily rows compact once; failed monthly writes undo raw moves too.
   */
  public function testMonthlyCompactionAndRollback(): void {
    $db = $this->container->get('database');
    foreach (['2025-09-01', '2025-09-30', '2025-10-01'] as $date) {
      $this->event(['timestamp' => strtotime($date . ' UTC')]);
    }
    $this->assertSame(3, $this->container->get('entity_metrics.rollup')->process()['processed']);
    $this->assertSame(3, (int) $db->select('entity_metrics_counts')->condition('granularity', 'day')->countQuery()->execute()->fetchField());
    $this->setTime(strtotime('2026-10-01 00:00:00 UTC'));
    $id = $this->event();
    $broken = new class($db, $this->container->get('lock'), $this->container->get('datetime.time')) extends MetricsRollup {

      /**
       * Fails during compaction, after raw rows have already been deleted.
       */
      protected function retain(string $table, array $group): array {
        if (($group['granularity'] ?? '') === 'month') {
          throw new \RuntimeException('Simulated monthly write failure.');
        }
        return parent::retain($table, $group);
      }

    };
    try {
      $broken->process();
      $this->fail('The failed monthly write was accepted.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Simulated monthly write failure.', $exception->getMessage());
    }
    $this->assertSame($id, (int) $db->select('entity_metrics_data', 'd')->fields('d', ['id'])->execute()->fetchField());
    $this->assertSame(3, $this->summaryCount('entity_metrics_counts'));
    $service = new MetricsRollup($db, $this->container->get('lock'), $this->container->get('datetime.time'));
    $this->assertSame(['processed' => 1, 'compacted' => 2], $service->process());
    $this->assertSame(2, (int) $db->select('entity_metrics_counts', 'c')->fields('c', ['event_count'])->condition('granularity', 'month')->execute()->fetchField());
    $this->assertSame(4, $this->summaryCount('entity_metrics_counts'));
    $this->assertSame(4, $this->summaryCount('entity_metrics_map'));
    $this->assertSame(['processed' => 0, 'compacted' => 0], $service->process());
  }

  /**
   * Upgrades create only missing tables and never consume source events.
   */
  public function testUpgrade(): void {
    $db = $this->container->get('database');
    $id = $this->event();
    $db->schema()->dropTable('entity_metrics_counts');
    $db->schema()->dropTable('entity_metrics_map');
    entity_metrics_update_10004();
    entity_metrics_update_10004();
    $this->assertSame(0, $this->summaryCount('entity_metrics_counts'));
    $this->assertSame(0, $this->summaryCount('entity_metrics_map'));
    $this->assertSame($id, (int) $db->select('entity_metrics_data', 'd')->fields('d', ['id'])->execute()->fetchField());
  }

  /**
   * Sets both controller and worker clocks.
   */
  private function setTime(int $now): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    $time->method('getRequestTime')->willReturn($now);
    $this->container->set('datetime.time', $time);
  }

  /**
   * Inserts a resolved event eligible for rollup unless explicitly overridden.
   */
  private function event(array $overrides = []): int {
    return (int) $this->container->get('database')->insert('entity_metrics_data')->fields($overrides + [
      'entity_type' => 'node',
      'entity_id' => $this->nodeId,
      'timestamp' => $this->now - 40 * 86400,
      'session_id' => 'test',
      'ip_address' => NULL,
      'cookie_set' => 0,
      'region_id' => $this->regionId,
      'geolocation_status' => 1,
    ])->execute();
  }

  /**
   * Counts the events retained in one summary table.
   */
  private function summaryCount(string $table): int {
    $query = $this->container->get('database')->select($table, 's');
    $query->addExpression('COALESCE(SUM(event_count), 0)', 'total');
    return (int) $query->execute()->fetchField();
  }

}
