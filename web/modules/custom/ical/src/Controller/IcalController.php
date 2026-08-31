<?php

declare(strict_types=1);

namespace Drupal\ical\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\Category;
use Eluceo\iCal\Domain\ValueObject\DateTime as ICalDateTime;
use Eluceo\iCal\Domain\ValueObject\Location;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Domain\ValueObject\Uri;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides iCal feed endpoints.
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

  /**
   * Returns a single-event ICS file.
   */
  public function getEventIcal(NodeInterface $node): Response {
    if ($node->getType() !== 'event' || !$node->isPublished()) {
      throw new NotFoundHttpException();
    }

    $event = $this->buildEvent($node);
    $calendar = new Calendar([$event]);
    $component = (new CalendarFactory())->createCalendar($calendar);

    $filename = 'tapahtuma-' . $node->id() . '.ics';
    return new Response((string) $component, 200, [
      'Content-Type' => 'text/calendar; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);
  }

  /**
   * Returns a filtered ICS feed of all events.
   */
  public function getIcal(Request $request): Response {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'event')
      ->accessCheck(FALSE);

    $conditions = $query->orConditionGroup();
    $has_optional_filter = FALSE;

    $organiser = (int) $request->query->get('organiser');
    if (!empty($organiser)) {
      $conditions->condition('field_event_organiser.entity:node.field_official_id', $organiser);
      $has_optional_filter = TRUE;
    }

    $city = $request->query->get('city');
    if (!empty($city)) {
      $city_group = $query->orConditionGroup()
        ->condition('field_place.entity:taxonomy_term.field_official_id', $city)
        ->condition('field_place.entity:taxonomy_term.field_municipality.entity:taxonomy_term.field_official_id', $city);
      $conditions->condition($city_group);
      $has_optional_filter = TRUE;
    }

    $region = $request->query->get('region');
    if (!empty($region)) {
      $region_group = $query->orConditionGroup()
        ->condition('field_place.entity:taxonomy_term.field_regions.entity:taxonomy_term.field_official_id', $region)
        ->condition('field_place.entity:taxonomy_term.field_municipality.entity:taxonomy_term.field_regions.entity:taxonomy_term.field_official_id', $region);
      $conditions->condition($region_group);
      $has_optional_filter = TRUE;
    }

    if ($has_optional_filter) {
      $query->condition($conditions);
    }
    $event_ids = $query->execute();

    $candidate_registration_by_event = [];
    $candidate = NULL;
    $candidate_id = (int) $request->query->get('candidate');
    if ($candidate_id && \Drupal::moduleHandler()->moduleExists('candidate_registration')) {
      $candidate = $storage->load($candidate_id);
      if (!$candidate instanceof NodeInterface || $candidate->getType() !== 'candidate' || !$candidate->isPublished()) {
        throw new NotFoundHttpException();
      }
      $event_ids = \Drupal::service('candidate_registration.manager')
        ->loadEventIdsForCandidate($candidate, array_map('intval', $event_ids));
      $candidate_registration_by_event = \Drupal::service('candidate_registration.manager')
        ->loadRegistrationsForCandidateByEvent($candidate, array_map('intval', $event_ids));
    }

    $nodes = $storage->loadMultiple($event_ids);

    $events = [];
    foreach ($nodes as $node) {
      $events[] = $this->buildEvent($node, $candidate_registration_by_event[(int) $node->id()] ?? NULL);
    }

    $calendar = new Calendar($events);
    $component = (new CalendarFactory())->createCalendar($calendar);

    return new Response((string) $component, 200, [
      'Content-Type' => 'text/calendar; charset=utf-8',
    ]);
  }

  /**
   * Builds an iCal Event entity from a node.
   */
  private function buildEvent(NodeInterface $node, mixed $candidate_registration = NULL): Event {
    $event = new Event(new UniqueIdentifier($node->uuid->getString()));

    if ($node->field_event_type->entity) {
      $event->addCategory(new Category($node->field_event_type->entity->getName()));
    }

    $event->setSummary($node->getTitle());

    $description_values = $node->field_description->getValue();
    if (!empty($description_values[0]['value'])) {
      $description = strip_tags((string) $description_values[0]['value']);
      if ($candidate_registration && $candidate_registration->hasField('note') && !$candidate_registration->get('note')->isEmpty()) {
        $description .= "\n\n" . (string) $candidate_registration->get('note')->value;
      }
      $event->setDescription($description);
    }
    elseif ($candidate_registration && $candidate_registration->hasField('note') && !$candidate_registration->get('note')->isEmpty()) {
      $event->setDescription((string) $candidate_registration->get('note')->value);
    }

    $location_parts = [];
    $place = $node->field_place->entity;
    if ($place) {
      $location_parts[] = $place->getName();
      if ($place->hasField('field_street_address') && !$place->field_street_address->isEmpty()) {
        $location_parts[] = (string) $place->field_street_address->getString();
      }
      if ($place->hasField('field_municipality') && !$place->field_municipality->isEmpty() && $place->field_municipality->entity) {
        $location_parts[] = $place->field_municipality->entity->getName();
      }
    }
    if ($location_parts) {
      $event->setLocation(new Location(implode(', ', $location_parts)));
    }

    $event->setUrl(new Uri($node->toUrl('canonical', ['absolute' => TRUE])->toString()));

    $datetime_values = $node->field_event_datetime->getValue();
    if (!empty($datetime_values[0]['value'])) {
      $utc = new DateTimeZone('UTC');
      $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $datetime_values[0]['value'], $utc);
      $end_str = $datetime_values[0]['end_value'] ?: $datetime_values[0]['value'];
      $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $end_str, $utc);
      $event->setOccurrence(new TimeSpan(
        new ICalDateTime($start, TRUE),
        new ICalDateTime($end, TRUE),
      ));
    }

    return $event;
  }

}
