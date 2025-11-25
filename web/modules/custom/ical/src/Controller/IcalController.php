<?php

declare(strict_types=1);

namespace Drupal\ical\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns ical.
 */
class IcalController implements ContainerInjectionInterface {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
    );
  }

  public function getIcal(Request $request): Response {
    $storage = $this->entityTypeManager
      ->getStorage('node');
    $query = $storage
      ->getQuery()
      ->condition('type', 'event')
      ->accessCheck(FALSE);

    $organisers = (int) $request->query->get('organiser');
    if (!empty($organisers)) {
      $query->condition('field_event_organiser', $organisers);
    }

    $city = $request->query->get('city');
    if (!empty($city)) {
      $query->condition('field_place.entity:taxonomy_term.field_municipality.entity:taxonomy_term.field_official_id', $city);
    }

    $res = $query->execute();

    $nodes = $storage->loadMultiple($res);
    $events = [];
    foreach ($nodes as $node) {
      $uuid = new \Eluceo\iCal\Domain\ValueObject\UniqueIdentifier($node->uuid->getString());
      $event = new \Eluceo\iCal\Domain\Entity\Event($uuid);
      $event->addCategory(new \Eluceo\iCal\Domain\ValueObject\Category($node->field_event_type->entity->getName()));
      $event->setSummary($node->getTitle());
      $event->setDescription(strip_tags((string) $node->field_description->getValue()[0]['value']));

      $location = $node->field_place->entity;
      $location_parts = [
        $location->getName(),
        (string) $location->field_street_address->getString(),
        $location->field_municipality->entity->getName(),
      ];
      $event->setLocation(new \Eluceo\iCal\Domain\ValueObject\Location(implode(', ', $location_parts)));
      $event->setUrl(new \Eluceo\iCal\Domain\ValueObject\Uri($node->toUrl('canonical', ['absolute' => TRUE])->toString()));
      $event->setOccurrence(
        new \Eluceo\iCal\Domain\ValueObject\TimeSpan(
          new \Eluceo\iCal\Domain\ValueObject\DateTime(
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $node->field_event_datetime->getValue()[0]['value'], new \DateTimeZone('UTC')), TRUE
          ),
          new \Eluceo\iCal\Domain\ValueObject\DateTime(
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $node->field_event_datetime->getValue()[0]['end_value'], new \DateTimeZone('UTC')), TRUE
          )
        )
      );
      $events[] = $event;
    }
    
    $calendar = new \Eluceo\iCal\Domain\Entity\Calendar($events);

    $component_factory = new \Eluceo\iCal\Presentation\Factory\CalendarFactory();
    $component = $component_factory->createCalendar($calendar);

    $response = new Response((string) $component, 200, ['Content-Type: text/calendar; charset=utf-8']);
    return $response;
  }

}
