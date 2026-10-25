<?php

namespace Drupal\however_customizations\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\however_customizations\Service\MigrationMap;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects /node/OLD to the unified node created from legacy node OLD.
 *
 * A request for /node/N of a unified node is redirected to the node's own
 * alias, so the text links the rewrite points at /node/N end on public URLs.
 * The redirects come from the migration map, so they exist exactly as long as
 * the unified nodes do: rolling a stage back removes the map rows, and the
 * redirects for that stage stop with them. Only the bare path /node/N is
 * redirected. The legacy node stays reachable through its /old alias and its
 * edit and delete pages.
 *
 * The redirects are off until the redirect_old_nids setting is turned on, and
 * use the status code in redirect_status (302 while rehearsing, 301 at
 * cutover).
 */
class LegacyNodeRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a LegacyNodeRedirectSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\however_customizations\Service\MigrationMap $migrationMap
   *   The migration map.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $urlGenerator
   *   The URL generator.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected MigrationMap $migrationMap,
    protected UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the router listener, before the controller runs.
    $events[KernelEvents::REQUEST][] = ['onRequest', 30];
    return $events;
  }

  /**
   * Redirects a request for a migrated legacy node path.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
      return;
    }
    if (!preg_match('#^/node/(\d+)$#', $request->getPathInfo(), $matches)) {
      return;
    }

    $settings = $this->configFactory->get('however_customizations.settings');
    if (!$settings->get('redirect_old_nids')) {
      return;
    }

    $nid = (int) $matches[1];
    // A legacy node goes to its unified twin; a unified node goes to its own
    // alias, so links written as /node/N end up on the public URL.
    $new_nid = $this->migrationMap->getNewNid($nid);
    if ($new_nid === NULL) {
      if ($this->migrationMap->getOldNid($nid) === NULL) {
        return;
      }
      $new_nid = $nid;
    }

    $status = (int) $settings->get('redirect_status') === Response::HTTP_MOVED_PERMANENTLY
      ? Response::HTTP_MOVED_PERMANENTLY
      : Response::HTTP_FOUND;
    $target = $this->urlGenerator->generateFromRoute('entity.node.canonical', ['node' => $new_nid]);
    if ($target === $request->getPathInfo()) {
      // The unified node has no alias; there is nothing to redirect to.
      return;
    }
    $query = $request->getQueryString();
    if ($query !== NULL && $query !== '') {
      $target .= '?' . $query;
    }
    $event->setResponse(new RedirectResponse($target, $status));
  }

}
