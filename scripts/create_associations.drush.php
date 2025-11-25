<?php

use Drupal\node\Entity\Node;

if (($handle = fopen("/tmp/assoc.csv", "r")) !== FALSE) {
  while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $new_article = Node::create(['type' => 'organisation']);
    $new_article->set('title', $data[1]);
    $new_article->set('field_official_id', $data[0]);
    $new_article->enforceIsNew();
    $new_article->save();
  }
}
