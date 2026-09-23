<?php

declare(strict_types=1);

namespace Drupal\Tests\entity_metrics\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\entity_metrics\Controller\VisitController;
use Drupal\entity_metrics\GeolocationBackfill;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Tests real SQL, local MMDB lookup, and the tracking trust boundary.
 */
#[Group('entity_metrics')]
#[RunTestsInSeparateProcesses]
class EntityMetricsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'node', 'text', 'entity_metrics'];

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
    $this->config('entity_metrics.settings')->set('geolocation_database', dirname(__DIR__, 2) . '/fixtures/GeoLite2-City-Test.mmdb')->save();
  }

  /**
   * Bad identifiers, inaccessible entities, and non-node paths fail closed.
   */
  public function testRoutesFailClosed(): void {
    $controller = VisitController::create($this->container);
    $node = Node::create(['type' => 'page', 'title' => 'Public', 'status' => 1]);
    $node->save();
    $draft = Node::create(['type' => 'page', 'title' => 'Draft', 'status' => 0]);
    $draft->save();
    foreach ([
      NULL, [], 'node/-1', 'node/0', 'node/01', 'node/1.0', 'node/1e2', 'node/+1',
      'node/4294967296', 'node/999999', 'node/' . $draft->id(),
      'user/' . $node->id(), 'node/1/edit', '/node/1', 'https://example.org/node/1',
    ] as $path) {
      try {
        $controller->recordVisit(Request::create('/', 'POST', ['currentPath' => $path]));
        $this->fail('Invalid tracking path was accepted.');
      }
      catch (HttpExceptionInterface $exception) {
        $this->assertContains($exception->getStatusCode(), [400, 404]);
      }
    }
    foreach ([
      ['node', '-1'], ['node', '0'], ['node', []], ['node', '4294967296'],
      ['node', '999999'], ['node', $draft->id()], ['user', $node->id()],
    ] as [$type, $id]) {
      try {
        $controller->getVisits($type, $id);
        $this->fail('Invalid metrics read was accepted.');
      }
      catch (HttpExceptionInterface $exception) {
        $this->assertContains($exception->getStatusCode(), [400, 404]);
      }
    }
    $db = $this->container->get('database');
    $this->assertSame(0, (int) $db->select('entity_metrics_data')->countQuery()->execute()->fetchField());
    $request = Request::create('/', 'POST', ['currentPath' => 'node/' . $node->id()], [], [], ['REMOTE_ADDR' => '2.125.160.216']);
    for ($i = 0; $i < 20; $i++) {
      $this->assertSame(200, $controller->recordVisit($request)->getStatusCode());
    }
    $this->assertSame(429, $controller->recordVisit($request)->getStatusCode());
    $this->assertSame(['total' => 20, 'monthly' => 20], json_decode($controller->getVisits('node', $node->id())->getContent(), TRUE));
    $this->assertTrue($controller->getVisits('node', $node->id())->headers->hasCacheControlDirective('no-store'));
    $this->container->get('router.builder')->rebuild();
    $route = $this->container->get('router.route_provider')->getRouteByName('entity_metrics.view');
    $this->assertSame('[1-9][0-9]{0,9}', $route->getRequirement('id'));
  }

  /**
   * Backfill resumes in batches and preserves recent flood-check addresses.
   */
  public function testLocalBackfill(): void {
    $db = $this->container->get('database');
    $service = $this->container->get('entity_metrics.geolocation');
    $first = $this->event('2.125.160.216');
    $second = $this->event('2.125.160.216');
    $private = $this->event('192.168.1.1');
    $invalid = $this->event('not-an-ip');
    $unknown = $this->event('8.8.8.8');
    $no_ip = $this->event(NULL);
    $recent = $this->event('2.125.160.216', time());
    $first_batch = $service->process(2);
    $this->assertSame(2, $first_batch['located']);
    $this->assertSame($second, $first_batch['last_id']);
    $this->assertSame(4, $service->process(20)['unknown']);
    $this->assertSame(0, $service->process()['processed']);
    $regions = $db->select('entity_metrics_regions', 'r')->fields('r')->execute()->fetchAll();
    $this->assertCount(1, $regions);
    $this->assertSame('GB', $regions[0]->country);
    $this->assertSame('England', $regions[0]->region);
    $this->assertSame('Boxford', $regions[0]->city);
    $this->assertEquals(51.75, $regions[0]->latitude);
    $this->assertEquals(-1.25, $regions[0]->longitude);
    $events = $db->select('entity_metrics_data', 'd')->fields('d')->execute()->fetchAllAssoc('id');
    $this->assertSame($events[$first]->region_id, $events[$second]->region_id);
    $this->assertNull($events[$first]->ip_address);
    foreach ([$private, $invalid, $no_ip] as $id) {
      $this->assertNull($events[$id]->ip_address);
      $this->assertNull($events[$id]->region_id);
      $this->assertSame(2, (int) $events[$id]->geolocation_status);
    }
    $this->assertSame('8.8.8.8', $events[$unknown]->ip_address);
    $this->assertSame(0, (int) $events[$recent]->geolocation_status);
    $this->assertSame('2.125.160.216', $events[$recent]->ip_address);
    $this->assertSame(1, $service->retryUnknown());
    $this->assertSame(1, $service->process()['unknown']);
    $this->assertNull(GeolocationBackfill::location(['country' => ['iso_code' => 'invalid']]));
    $this->assertNull(GeolocationBackfill::location(['country' => ['iso_code' => 'US']])['latitude']);
    $this->assertSame(7, (int) $db->select('entity_metrics_data')->countQuery()->execute()->fetchField());
    $ipv6 = $this->event('2001:218::');
    $this->assertSame(1, $service->process()['located']);
    $query = $db->select('entity_metrics_data', 'd');
    $query->join('entity_metrics_regions', 'r', 'r.id = d.region_id');
    $region = $query->fields('r')->condition('d.id', $ipv6)->execute()->fetchObject();
    $this->assertSame('JP', $region->country);
    $this->assertEquals(35.68536, $region->latitude);
  }

  /**
   * Missing databases and failed transactions never consume pending events.
   */
  public function testFailurePreservesData(): void {
    $db = $this->container->get('database');
    $id = $this->event('2.125.160.216');
    $this->config('entity_metrics.settings')->set('geolocation_database', '/missing/city.mmdb')->save();
    try {
      $this->container->get('entity_metrics.geolocation')->process();
      $this->fail('Missing database was accepted.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('missing', $exception->getMessage());
    }
    $event = $db->select('entity_metrics_data', 'd')->fields('d')->condition('id', $id)->execute()->fetchObject();
    $this->assertSame(0, (int) $event->geolocation_status);
    $this->assertSame('2.125.160.216', $event->ip_address);
    $this->config('entity_metrics.settings')->set('geolocation_database', dirname(__DIR__, 2) . '/fixtures/GeoLite2-City-Test.mmdb')->save();
    $this->assertSame(1, $this->container->get('entity_metrics.geolocation')->process()['located']);
    // Upgrade hooks are safe to rerun against an already updated schema.
    entity_metrics_update_10002();
    entity_metrics_update_10002();
    $this->assertTrue($db->schema()->indexExists('entity_metrics_data', 'geolocation_pending'));
  }

  /**
   * Existing installs gain indexed state without losing events or settings.
   */
  public function testUpgradeAndRollback(): void {
    $db = $this->container->get('database');
    $schema = $db->schema();
    $schema->dropIndex('entity_metrics_data', 'geolocation_pending');
    $schema->dropIndex('entity_metrics_data', 'ip_timestamp');
    $schema->dropField('entity_metrics_data', 'geolocation_status');
    $schema->dropUniqueKey('entity_metrics_regions', 'location_key');
    $schema->dropField('entity_metrics_regions', 'location_key');
    $this->config('entity_metrics.settings')->set('cookie', 'staff')
      ->clear('geolocation_enabled')->clear('geolocation_batch_size')->save();
    $first = $this->event('2.125.160.216');
    $this->event('81.2.69.160');
    entity_metrics_update_10002();
    $this->assertSame('staff', $this->config('entity_metrics.settings')->get('cookie'));
    $this->assertSame(500, $this->config('entity_metrics.settings')->get('geolocation_batch_size'));
    $service = new class($db, $this->container->get('config.factory'), $this->container->get('file_system'), $this->container->get('lock'), $this->container->get('datetime.time')) extends GeolocationBackfill {

      /**
       * Number of attempted regions.
       */
      private int $calls = 0;

      /**
       * Simulates a database write failure after one successful lookup.
       */
      protected function region(array $location): int {
        if (++$this->calls === 2) {
          throw new \RuntimeException('Simulated failure.');
        }
        return parent::region($location);
      }

    };
    try {
      $service->process();
      $this->fail('Simulated failure did not occur.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Simulated failure.', $exception->getMessage());
    }
    $this->assertSame(0, (int) $db->select('entity_metrics_regions')->countQuery()->execute()->fetchField());
    $event = $db->select('entity_metrics_data', 'd')->fields('d')->condition('id', $first)->execute()->fetchObject();
    $this->assertSame(0, (int) $event->geolocation_status);
    $this->assertSame('2.125.160.216', $event->ip_address);
    $this->assertSame(2, $this->container->get('entity_metrics.geolocation')->process()['located']);
  }

  /**
   * Inserts one old event without requiring a node for geolocation.
   */
  private function event(?string $ip, ?int $timestamp = NULL): int {
    return (int) $this->container->get('database')->insert('entity_metrics_data')->fields([
      'entity_type' => 'node',
      'entity_id' => 1,
      'timestamp' => $timestamp ?? time() - 120,
      'session_id' => 'test',
      'ip_address' => $ip,
      'cookie_set' => 0,
    ])->execute();
  }

}
