<?php

declare(strict_types=1);

namespace Drupal\vihreattapahtumat_location_widget\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides autocomplete endpoints for the location widget.
 */
class LocationAutocompleteController implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * Returns places and municipalities matching the query, plus a "create" sentinel.
   */
  public function autocomplete(Request $request): JsonResponse {
    $q = trim((string) $request->query->get('q', ''));
    $results = [];

    if ($q !== '') {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');

      // Places by name.
      $by_name = $storage->getQuery()
        ->condition('vid', 'place')
        ->condition('name', '%' . $q . '%', 'LIKE')
        ->accessCheck(FALSE)
        ->range(0, 6)
        ->execute();

      // Places by street address.
      $by_addr = $storage->getQuery()
        ->condition('vid', 'place')
        ->condition('field_street_address', '%' . $q . '%', 'LIKE')
        ->accessCheck(FALSE)
        ->range(0, 4)
        ->execute();

      $place_ids = array_slice(array_unique(array_merge(array_values($by_name), array_values($by_addr))), 0, 8);

      foreach ($storage->loadMultiple($place_ids) as $term) {
        $municipality = '';
        if (!$term->get('field_municipality')->isEmpty() && $term->get('field_municipality')->entity) {
          $municipality = $term->get('field_municipality')->entity->label();
        }
        $results[] = [
          'id' => (int) $term->id(),
          'label' => $term->label(),
          'bundle' => 'place',
          'secondary' => $municipality,
        ];
      }

      // Municipalities by name.
      $muni_ids = $storage->getQuery()
        ->condition('vid', 'municipality')
        ->condition('name', '%' . $q . '%', 'LIKE')
        ->accessCheck(FALSE)
        ->range(0, 4)
        ->execute();

      foreach ($storage->loadMultiple($muni_ids) as $term) {
        $results[] = [
          'id' => (int) $term->id(),
          'label' => $term->label(),
          'bundle' => 'municipality',
          'secondary' => '',
        ];
      }
    }

    // Always append the "create new" sentinel so the user can create a place
    // from any non-empty search text.
    $results[] = [
      'id' => '__create__',
      'label' => $q,
      'bundle' => 'create',
      'secondary' => '',
    ];

    return new JsonResponse($results);
  }

  /**
   * Returns municipalities matching the query (for the mini-form Kunta field).
   */
  public function municipalityAutocomplete(Request $request): JsonResponse {
    $q = trim((string) $request->query->get('q', ''));
    $results = [];

    if ($q !== '') {
      $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->condition('vid', 'municipality')
        ->condition('name', '%' . $q . '%', 'LIKE')
        ->accessCheck(FALSE)
        ->range(0, 8)
        ->execute();

      foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids) as $term) {
        $results[] = [
          'id' => (int) $term->id(),
          'label' => $term->label(),
        ];
      }
    }

    return new JsonResponse($results);
  }

}
