<?php

use Drupal\taxonomy\Entity\Term;

$taxonomyStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

$regions = [
  '02' => 326,
];
if (($handle = fopen("/tmp/alueet.csv", "r")) !== FALSE) {
  while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    // Create the region if it's missing. We could fetch the existing ones
    // programmatically, but can't be bothered to write that.
    if (!isset($regions[$data[1]])) {
      $term = Term::create([
        'name' => $data[2],
        'field_official_id' => $data[1],
        'vid' => 'region',
      ]);
      $term->save();
      $regions[$data[1]] = $term->id();
    }
    $query = $taxonomyStorage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_official_id', $data[0]);
    $res = $query->execute();
    if (empty($res)) {
      echo 'Failed to find ' . $data[0] . "\n";
      continue;
    }

    $municipality = $taxonomyStorage->load(reset($res));
    $municipality->field_regions->target_id = $regions[$data[1]];
    $municipality->save();
  }
}
