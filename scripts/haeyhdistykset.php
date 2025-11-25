<?php

$fp = fopen('/tmp/assoc.csv', 'w');
$data = file_get_contents('/tmp/test.txt');
preg_match_all('/association-([0-9]*).*?searchResults__title"> ([^<]*) </', $data, $matches);
foreach (array_keys($matches[0]) as $key) {
  $fields = [$matches[1][$key], $matches[2][$key]];
  fputcsv($fp, $fields, ',', '"', '');
}
