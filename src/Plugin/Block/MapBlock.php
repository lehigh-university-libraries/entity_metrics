<?php

namespace Drupal\entity_metrics\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a map block.
 *
 * @Block(
 *   id="entity_metrics_map",
 *   admin_label = @Translation("Metrics Map block")
 * )
 */
class MapBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor for MapBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match interface.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = ['#cache' => ['tags' => ['entity_metrics_regions']]];
    $build['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => ['map-header'],
      ],
      '#prefix' => '<h2>Collection Views</h2>',
    ];
    $build['map'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => ['map'],
      ],
    ];
    $build['#attached']['library'][] = 'entity_metrics/map';

    $members = $this->database->select('node__field_member_of', 'm')->fields('m', ['entity_id'])
      ->condition('field_member_of_target_id', $this->routeMatch->getParameter('node')->id());
    $events = $this->database->select('entity_metrics_data', 'e');
    $events->fields('e', ['entity_id', 'region_id'])->condition('entity_type', 'node');
    $events->condition('entity_id', $members, 'IN');
    $events->addExpression('MAX(timestamp)', 'timestamp');
    $events->addExpression('FLOOR(e.timestamp / 86400)', 'event_day');
    $events->groupBy('entity_id')->groupBy('region_id')->groupBy('event_day');
    $history = $this->database->select('entity_metrics_map', 'h');
    $history->fields('h', ['entity_id', 'region_id'])->condition('entity_type', 'node');
    $history->condition('entity_id', clone $members, 'IN');
    $history->addField('h', 'last_timestamp', 'timestamp');
    $history->addExpression('0', 'event_day');
    $events->union($history, 'ALL');
    $query = $this->database->select($events, 'd');
    $query->innerJoin('entity_metrics_regions', 'r', 'r.id = d.region_id');
    $query->fields('d', ['entity_id', 'timestamp'])->fields('r', ['latitude', 'longitude', 'city', 'region', 'country']);
    $query->isNotNull('latitude')->isNotNull('longitude')->distinct();
    $results = $query->execute();

    foreach ($results as $result) {
      $node = $this->entityTypeManager->getStorage('node')->load($result->entity_id);
      if ($node && $node->access()) {
        $build['#attached']['drupalSettings']['entityMetrics'][] = [
          'label' => $node->label(),
          'link' => $node->toUrl()->toString(),
          'date' => date('m/d/Y', $result->timestamp),
          'latitude' => $result->latitude,
          'longitude' => $result->longitude,
          'city' => $result->city,
          'region' => $result->region,
          'country' => $result->country,
        ];
      }
    }

    return $build;
  }

}
