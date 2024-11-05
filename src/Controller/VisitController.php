<?php

namespace Drupal\entity_metrics\Controller;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\SessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for custom flood checks.
 */
class VisitController extends ControllerBase {
  const FLOOD_EVENT_LIMIT = 20;
  const FLOOD_EVENT_WINDOW_SECONDS = 60;

  /**
   * The session manager.
   *
   * @var \Drupal\Core\Session\SessionManager
   */
  protected $session;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\Time
   */
  protected $time;

  /**
   * VisitController constructor.
   *
   * @param \Drupal\Core\Session\SessionManager $session
   *   The Drupal session manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\Core\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(SessionManager $session, Connection $database, Time $time) {
    $this->session = $session;
    $this->database = $database;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('session_manager'),
      $container->get('database'),
      $container->get('datetime.time')
    );
  }

  /**
   * Record when a site visitor views an entity.
   */
  public function recordVisit(Request $request) {
    $ip = $request->getClientIp();
    if ($this->checkFlood($ip)) {
      // Return a 429 response.
      $response = new Response();
      $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
      $response->setContent('Too Many Requests');

      return $response;
    }

    $currentPath = explode('/', $request->request->get('currentPath'));
    $entity_id = array_pop($currentPath);
    $this->database->insert('entity_metrics_data')
      ->fields([
        'entity_type' => 'node',
        'entity_id' => $entity_id,
        'session_id' => $this->session->getId(),
        'timestamp' => $this->time->getCurrentTime(),
        'ip_address' => $ip,
      ])
      ->execute();
    $response = new Response();
    $response->setStatusCode(Response::HTTP_OK);
    return $response;
  }

  /**
   * Get site visitor history views for a given entity.
   */
  public function getVisits($type, $id) {
    return new JsonResponse([
      'monthly' => $this->database->query('SELECT COUNT(id) FROM {entity_metrics_data}
        WHERE entity_type = :type
          AND entity_id = :id
          AND timestamp > :thirtyDays', [
            ':type' => $type,
            ':id' => $id,
            ':thirtyDays' => $this->time->getCurrentTime() - 2592000,
          ])->fetchField(),
      'total' => $this->database->query('SELECT COUNT(id) FROM {entity_metrics_data}
        WHERE entity_type = :type
          AND entity_id = :id', [
            ':type' => $type,
            ':id' => $id,
          ])->fetchField(),
    ]);
  }

  /**
   * Check the flood status.
   */
  public function checkFlood(string $ip) : bool {
    // Only allow recording 20 events every minute.
    return $this->database->query('SELECT COUNT(id) FROM {entity_metrics_data}
      WHERE ip_address = :ip
        AND timestamp > :timeout', [
          ':ip' => $ip,
          ':timeout' => $this->time->getCurrentTime() - self::FLOOD_EVENT_WINDOW_SECONDS,
        ])->fetchField() > self::FLOOD_EVENT_LIMIT;
  }

}
