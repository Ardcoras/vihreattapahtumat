<?php

use Drupal\taxonomy\Entity\Term;

if (($handle = fopen("/tmp/kunnat.csv", "r")) !== FALSE) {
  while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $nro = str_replace("'", '', $data[0]);
    $term = Term::create([
      'name' => $data[1],
      'field_official_id' => $nro,
      'vid' => 'municipality',
    ])->save();
  }
}
