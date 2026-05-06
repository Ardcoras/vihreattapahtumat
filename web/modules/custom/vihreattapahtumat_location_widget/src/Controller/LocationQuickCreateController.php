<?php

declare(strict_types=1);

namespace Drupal\vihreattapahtumat_location_widget\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles quick creation of a new place term from the location widget.
 */
class LocationQuickCreateController implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * Creates a new place term and returns its ID and chip label.
   */
  public function save(Request $request): JsonResponse {
    // Validate CSRF token sent in the request header.
    $token = $request->headers->get('X-Location-Widget-Token', '');
    if (!\Drupal::csrfToken()->validate($token, 'location-quick-create')) {
      return new JsonResponse(['error' => 'Invalid token'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if ($data === NULL) {
      return new JsonResponse(['error' => 'Invalid JSON'], 400);
    }

    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
      return new JsonResponse(['error' => 'Name is required'], 400);
    }

    $municipality_id = (int) ($data['municipality_id'] ?? 0);
    if ($municipality_id <= 0) {
      return new JsonResponse(['error' => 'Municipality is required'], 400);
    }

    $municipality = $this->entityTypeManager->getStorage('taxonomy_term')->load($municipality_id);
    if (!$municipality || $municipality->bundle() !== 'municipality') {
      return new JsonResponse(['error' => 'Municipality not found'], 400);
    }

    $street_address = trim((string) ($data['street_address'] ?? ''));

    $term = Term::create([
      'vid' => 'place',
      'name' => $name,
      'field_municipality' => ['target_id' => $municipality_id],
      'field_street_address' => $street_address,
    ]);
    $term->save();

    return new JsonResponse([
      'id' => (int) $term->id(),
      'label' => $term->label() . ', ' . $municipality->label(),
    ]);
  }

}
