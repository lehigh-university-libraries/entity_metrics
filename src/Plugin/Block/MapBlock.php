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
    $build = [];
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

    $results = $this->database->query("SELECT d.entity_id, d.timestamp, latitude, longitude, city, region, country
      FROM entity_metrics_data d
      INNER JOIN entity_metrics_regions r ON r.id = d.region_id
      INNER JOIN node__field_member_of m ON m.entity_id = d.entity_id
      WHERE d.entity_type = 'node' AND field_member_of_target_id = :id
      GROUP BY FROM_UNIXTIME(d.timestamp, 'YYYMMMDD'), entity_id, city ", [
        ':id' => $this->routeMatch->getParameter('node')->id(),
      ]);

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
