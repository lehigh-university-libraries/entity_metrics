<?php

namespace Drupal\entity_metrics\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records and exposes counts only for valid, accessible entities.
 */
class VisitController extends ControllerBase {

  public function __construct(
    protected SessionManagerInterface $session,
    protected Connection $database,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $metricsConfig,
    protected EntityTypeManagerInterface $entityTypes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('session_manager'),
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Fails closed before recording visits or querying counts.
   */
  protected function requireViewableEntity($type, $id): void {
    if (!in_array($type, ['node', 'media'], TRUE)
      || (!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)
      || (int) $id > 4294967295) {
      throw new BadRequestHttpException('Invalid entity identifier.');
    }
    if (!$this->entityTypes->hasDefinition($type)) {
      throw new NotFoundHttpException();
    }
    $entity = $this->entityTypes->getStorage($type)->load($id);
    if (!$entity || !$entity->access('view', $this->currentUser())) {
      throw new NotFoundHttpException();
    }
  }

  /**
   * Records visits only for canonical node paths supplied by Drupal's JS.
   */
  public function recordVisit(Request $request) {
    $path = $request->request->all()['currentPath'] ?? NULL;
    if (!is_string($path) || !preg_match('#^node/([1-9][0-9]{0,9})$#D', $path, $matches)) {
      throw new BadRequestHttpException('A canonical node path is required.');
    }
    $this->requireViewableEntity('node', $matches[1]);
    $ip = $request->getClientIp();
    if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
      throw new BadRequestHttpException('Invalid client address.');
    }
    $this->moduleHandler()->invokeAll('entity_metrics_visit_presave', [$ip]);
    $cookie = $this->metricsConfig->get('entity_metrics.settings')->get('cookie');
    $this->database->insert('entity_metrics_data')->fields([
      'entity_type' => 'node',
      'entity_id' => (int) $matches[1],
      'session_id' => $this->session->getId(),
      'timestamp' => $this->time->getCurrentTime(),
      'ip_address' => $ip,
      'cookie_set' => $cookie && $request->cookies->get($cookie) === '1' ? 1 : 0,
    ])->execute();
    return new Response('', Response::HTTP_OK, ['Cache-Control' => 'no-store']);
  }

  /**
   * Returns counts only when the caller can view the entity.
   */
  public function getVisits($type, $id) {
    $this->requireViewableEntity($type, $id);
    $query = $this->database->select('entity_metrics_data', 'd');
    $query->condition('entity_type', $type)->condition('entity_id', $id)->condition('cookie_set', 0);
    $query->addExpression('COUNT(*)', 'total');
    $query->addExpression('COALESCE(SUM(CASE WHEN timestamp > :since THEN 1 ELSE 0 END), 0)', 'monthly', [
      ':since' => $this->time->getCurrentTime() - 2592000,
    ]);
    $counts = $query->execute()->fetchAssoc();
    return new JsonResponse(array_map('intval', $counts), 200, ['Cache-Control' => 'no-store']);
  }

}
