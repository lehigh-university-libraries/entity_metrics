<?php

declare(strict_types=1);

namespace Drupal\Tests\entity_metrics\Kernel;

use Drupal\Core\Session\SessionManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests range recording and historical download cleanup against real SQL.
 */
#[Group('entity_metrics')]
#[RunTestsInSeparateProcesses]
class MediaDownloadsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'node', 'text', 'entity_metrics'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['entity_metrics']);
    $this->container->get('module_handler')->loadInclude('entity_metrics', 'install');
    $this->container->get('module_handler')->loadInclude('entity_metrics', 'post_update.php');
    entity_metrics_install();
  }

  /**
   * Only requests without a Range header record downloads.
   */
  public function testRangeRequests(): void {
    $database = $this->container->get('database');
    $database->schema()->createTable('file_managed', [
      'fields' => [
        'fid' => ['type' => 'int'],
        'uri' => ['type' => 'varchar', 'length' => 255],
      ],
    ]);
    $database->insert('file_managed')->fields(['fid' => 1, 'uri' => 'fedora://test'])->execute();
    foreach (['audio_file', 'document', 'file', 'image', 'video_file'] as $field) {
      $database->schema()->createTable('media__field_media_' . $field, [
        'fields' => [
          'entity_id' => ['type' => 'int'],
          'field_media_' . $field . '_target_id' => ['type' => 'int'],
        ],
      ]);
    }
    $database->insert('media__field_media_video_file')->fields([
      'entity_id' => 42,
      'field_media_video_file_target_id' => 1,
    ])->execute();
    $session = $this->createMock(SessionManagerInterface::class);
    $session->method('getId')->willReturn('test');
    $this->container->set('session_manager', $session);
    foreach ([
      [NULL, 1], ['bytes=0-', 0], ['bytes=0-1023', 0], ['bytes=00-1023', 0],
      ['bytes=0-99,200-299', 0], ['bytes=1-', 0], ['bytes=1024-2047', 0],
      ['bytes=-500', 0], ['bytes=100-199,0-99', 0], ['', 0],
    ] as [$range, $expected]) {
      $database->delete('entity_metrics_data')->execute();
      $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '2.125.160.216']);
      if ($range !== NULL) {
        $request->headers->set('Range', $range);
      }
      $this->container->get('request_stack')->push($request);
      try {
        $this->assertNull(entity_metrics_file_download('fedora://test'));
      }
      finally {
        $this->container->get('request_stack')->pop();
      }
      $this->assertSame($expected, (int) $database->select('entity_metrics_data')->countQuery()->execute()->fetchField(), (string) $range);
    }
  }

  /**
   * The post-update preserves grouping boundaries across batches and reruns.
   */
  public function testCleanup(): void {
    $database = $this->container->get('database');
    // A 12:45 first hit groups through 13:45, regardless of the clock hour.
    $first = 12 * 3600 + 45 * 60;
    $this->event(['timestamp' => $first]);
    $this->event(['timestamp' => $first]);
    $this->event(['timestamp' => $first + 3600, 'session_id' => 'another-visitor']);
    $this->event(['timestamp' => $first + 3601]);
    $this->event(['timestamp' => $first + 6000]);
    $retained = [$this->event(['timestamp' => $first + 7202])];
    foreach ([
      ['region_id' => 2], ['entity_id' => 43], ['entity_type' => 'node'],
      ['region_id' => NULL], ['region_id' => NULL],
    ] as $overrides) {
      $retained[] = $this->event($overrides);
    }
    for ($i = 0; $i < 501; $i++) {
      $this->event(['timestamp' => $first + 900]);
    }
    $sandbox = [];
    entity_metrics_post_update_deduplicate_media_downloads($sandbox);
    $this->assertSame(500, $sandbox['processed']);
    $this->assertLessThan(1, $sandbox['#finished']);
    // An event inserted during the update is outside its initial snapshot.
    $new = $this->event(['timestamp' => $first]);
    do {
      $message = entity_metrics_post_update_deduplicate_media_downloads($sandbox);
    } while ($sandbox['#finished'] < 1);
    $this->assertSame('Removed 506 suspected viewer download events.', (string) $message);
    $this->assertEquals([...$retained, $new], $database->select('entity_metrics_data', 'd')->fields('d', ['id'])->orderBy('id')->execute()->fetchCol());
    $database->delete('entity_metrics_data')->condition('id', $new)->execute();
    $sandbox = [];
    $this->assertSame('Removed 0 suspected viewer download events.', (string) entity_metrics_post_update_deduplicate_media_downloads($sandbox));
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertEquals($retained, $database->select('entity_metrics_data', 'd')->fields('d', ['id'])->orderBy('id')->execute()->fetchCol());
    $this->assertTrue($this->container->get('lock')->lockMayBeAvailable('entity_metrics.geolocation'));
  }

  /**
   * Empty sites complete the update without attempting deletion.
   */
  public function testEmptyCleanup(): void {
    $sandbox = [];
    $this->assertSame('Removed 0 suspected viewer download events.', (string) entity_metrics_post_update_deduplicate_media_downloads($sandbox));
    $this->assertSame(1, $sandbox['#finished']);
  }

  /**
   * A later batch's repeat also removes the group's previously kept first hit.
   */
  public function testViewerGroupAcrossBatches(): void {
    $database = $this->container->get('database');
    $retained = [];
    for ($media = 1; $media < 500; $media++) {
      $retained[] = $this->event(['entity_id' => $media]);
    }
    $first = $this->event(['entity_id' => 500]);
    $second = $this->event(['entity_id' => 500, 'timestamp' => 1100]);
    $sandbox = [];
    entity_metrics_post_update_deduplicate_media_downloads($sandbox);
    $this->assertSame(0, $sandbox['deleted']);
    $this->assertLessThan(1, $sandbox['#finished']);
    $this->assertEquals([...$retained, $first, $second], $database->select('entity_metrics_data', 'd')->fields('d', ['id'])->orderBy('id')->execute()->fetchCol());
    entity_metrics_post_update_deduplicate_media_downloads($sandbox);
    $this->assertSame(2, $sandbox['deleted']);
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertEquals($retained, $database->select('entity_metrics_data', 'd')->fields('d', ['id'])->orderBy('id')->execute()->fetchCol());
  }

  /**
   * Inserts a raw download with optional field overrides.
   */
  private function event(array $overrides = []): int {
    return (int) $this->container->get('database')->insert('entity_metrics_data')->fields($overrides + [
      'entity_type' => 'media',
      'entity_id' => 42,
      'region_id' => 1,
      'timestamp' => 1000,
      'session_id' => 'test',
      'cookie_set' => 0,
    ])->execute();
  }

}
