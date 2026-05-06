<?php

declare(strict_types=1);

namespace Drupal\vihreattapahtumat_location_widget\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns a rendered HTML preview of a place or municipality term.
 */
class LocationPreviewController implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * Returns JSON {html} with the preview markup for the given term.
   */
  public function preview(Request $request): JsonResponse {
    $tid = (int) $request->query->get('tid', 0);
    if ($tid <= 0) {
      throw new NotFoundHttpException();
    }

    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    if (!$term) {
      throw new NotFoundHttpException();
    }

    if ($term->bundle() === 'place') {
      $name = $term->label();
      $address = (string) ($term->get('field_street_address')->value ?? '');
      $municipality = '';
      if (!$term->get('field_municipality')->isEmpty() && $term->get('field_municipality')->entity) {
        $municipality = $term->get('field_municipality')->entity->label();
      }

      $lines = [];
      if ($address !== '') {
        $lines[] = htmlspecialchars($address);
      }
      if ($municipality !== '') {
        $lines[] = htmlspecialchars($municipality);
      }

      $html = '<div class="lw-preview">'
        . '<span class="lw-preview__icon">📍</span>'
        . '<div class="lw-preview__body">'
        . '<span class="lw-preview__name">' . htmlspecialchars($name) . '</span>'
        . ($lines ? '<span class="lw-preview__address">' . implode('<br>', $lines) . '</span>' : '')
        . '</div>'
        . '</div>';
    }
    elseif ($term->bundle() === 'municipality') {
      $html = '<div class="lw-preview">'
        . '<span class="lw-preview__icon">🏙</span>'
        . '<div class="lw-preview__body">'
        . '<span class="lw-preview__name">' . htmlspecialchars($term->label()) . '</span>'
        . '<span class="lw-preview__badge">' . t('Kunta') . '</span>'
        . '</div>'
        . '</div>';
    }
    else {
      throw new NotFoundHttpException();
    }

    return new JsonResponse(['html' => $html]);
  }

}
