<?php

namespace Drupal\vihreattapahtumat_geocoder\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\taxonomy\Entity\Term;

/**
 * Drush commands for geocoding place taxonomy terms.
 */
class GeocoderCommands extends DrushCommands {

  /**
   * Geocode all place taxonomy terms that have a street address.
   */
  #[CLI\Command(name: 'vihreattapahtumat:geocode-places', aliases: ['vt:gp'])]
  #[CLI\Option(name: 'force', description: 'Re-geocode even if coordinates already exist.')]
  #[CLI\Usage(name: 'drush vt:gp', description: 'Geocode all places missing coordinates.')]
  #[CLI\Usage(name: 'drush vt:gp --force', description: 'Re-geocode all places.')]
  public function geocodePlaces(array $options = ['force' => FALSE]) {
    $query = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'place')
      ->accessCheck(FALSE);
    $tids = $query->execute();

    if (empty($tids)) {
      $this->logger()->warning('No place terms found.');
      return;
    }

    $terms = Term::loadMultiple($tids);
    $http_client = \Drupal::httpClient();
    $adapter = new \Http\Adapter\Guzzle7\Client($http_client);
    $provider = \Geocoder\Provider\Nominatim\Nominatim::withOpenStreetMapServer($adapter, 'Drupal/vihreattapahtumat');
    $geocoder = new \Geocoder\StatefulGeocoder($provider);
    $count = 0;

    foreach ($terms as $term) {
      if (!$term->hasField('field_street_address') || !$term->hasField('field_location')) {
        continue;
      }

      if (!$options['force'] && !$term->get('field_location')->isEmpty()) {
        $this->logger()->notice('Skipping "@name" (already geocoded).', ['@name' => $term->getName()]);
        continue;
      }

      $street = $term->get('field_street_address')->value;
      if (empty($street)) {
        $this->logger()->notice('Skipping "@name" (no street address).', ['@name' => $term->getName()]);
        continue;
      }

      $parts = [$street];
      if ($term->hasField('field_municipality') && $term->get('field_municipality')->entity) {
        $parts[] = $term->get('field_municipality')->entity->getName();
      }
      $parts[] = 'Finland';
      $address = implode(', ', $parts);

      try {
        // Nominatim fair-use: max 1 request per second.
        if ($count > 0) {
          sleep(1);
        }

        $result = $geocoder->geocodeQuery(\Geocoder\Query\GeocodeQuery::create($address));

        if ($result->count() > 0) {
          $coordinates = $result->first()->getCoordinates();
          $lat = $coordinates->getLatitude();
          $lon = $coordinates->getLongitude();
          $term->set('field_location', ['value' => "POINT($lon $lat)"]);
          $term->save();
          $this->logger()->success('Geocoded "@address" to @lat, @lon', ['@address' => $address, '@lat' => $lat, '@lon' => $lon]);
          $count++;
        }
        else {
          $this->logger()->warning('No result for "@address"', ['@address' => $address]);
        }
      }
      catch (\Exception $e) {
        $this->logger()->error('Failed for "@address": @message', ['@address' => $address, '@message' => $e->getMessage()]);
      }
    }

    $this->logger()->success('Geocoded @count place terms.', ['@count' => $count]);
  }

}
