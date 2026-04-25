<?php

declare(strict_types=1);

namespace Drupal\vihreattapahtumat_ical_helper\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

class IcalHelperController extends ControllerBase {

  public function build(): array {
    return [
      '#theme' => 'ical_helper',
      '#municipalities' => $this->loadTermOptions('municipality'),
      '#regions' => $this->loadTermOptions('region'),
      '#organisers' => $this->loadOrganiserOptions(),
      '#feed_base' => Url::fromRoute('ical', [], ['absolute' => TRUE])->toString(),
      '#attached' => ['library' => ['vihreattapahtumat_ical_helper/helper']],
      '#cache' => [
        'tags' => [
          'taxonomy_term_list:municipality',
          'taxonomy_term_list:region',
          'node_list:organisation',
        ],
      ],
    ];
  }

  private function loadTermOptions(string $vid): array {
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', $vid)
      ->exists('field_official_id')
      ->accessCheck(FALSE)
      ->sort('name')
      ->execute();

    $options = [];
    foreach ($storage->loadMultiple($tids) as $term) {
      $id = $term->get('field_official_id')->value;
      if ($id !== NULL && $id !== '') {
        $options[] = ['id' => $id, 'name' => $term->label()];
      }
    }
    return $options;
  }

  private function loadOrganiserOptions(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'organisation')
      ->condition('status', 1)
      ->exists('field_official_id')
      ->accessCheck(FALSE)
      ->sort('title')
      ->execute();

    $options = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $id = $node->get('field_official_id')->value;
      if ($id !== NULL && $id !== '') {
        $options[] = ['id' => $id, 'name' => $node->label()];
      }
    }
    return $options;
  }

}
