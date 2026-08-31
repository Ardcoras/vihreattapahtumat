<?php

declare(strict_types=1);

namespace Drupal\candidate_registration;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Shared candidate registration lookup and access helpers.
 */
final class CandidateRegistrationManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Returns the registration for an event/candidate pair, if one exists.
   */
  public function loadRegistration(NodeInterface $event, NodeInterface $candidate): ?CandidateRegistrationInterface {
    $ids = $this->entityTypeManager
      ->getStorage('candidate_registration')
      ->getQuery()
      ->condition('event', $event->id())
      ->condition('candidate', $candidate->id())
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    $registration = $this->entityTypeManager
      ->getStorage('candidate_registration')
      ->load(reset($ids));

    return $registration instanceof CandidateRegistrationInterface ? $registration : NULL;
  }

  /**
   * Loads all registrations for an event.
   *
   * @return \Drupal\candidate_registration\CandidateRegistrationInterface[]
   *   Candidate registration entities.
   */
  public function loadRegistrationsForEvent(NodeInterface $event): array {
    $ids = $this->entityTypeManager
      ->getStorage('candidate_registration')
      ->getQuery()
      ->condition('event', $event->id())
      ->accessCheck(FALSE)
      ->execute();

    if (!$ids) {
      return [];
    }

    return array_filter(
      $this->entityTypeManager->getStorage('candidate_registration')->loadMultiple($ids),
      static fn ($entity): bool => $entity instanceof CandidateRegistrationInterface,
    );
  }

  /**
   * Loads published event ids for a candidate, optionally constrained by ids.
   */
  public function loadEventIdsForCandidate(NodeInterface $candidate, ?array $allowed_event_ids = NULL): array {
    $query = $this->entityTypeManager
      ->getStorage('candidate_registration')
      ->getQuery()
      ->condition('candidate', $candidate->id())
      ->accessCheck(FALSE);

    if ($allowed_event_ids !== NULL) {
      if ($allowed_event_ids === []) {
        return [];
      }
      $query->condition('event', $allowed_event_ids, 'IN');
    }

    $registration_ids = $query->execute();
    if (!$registration_ids) {
      return [];
    }

    $event_ids = [];
    foreach ($this->entityTypeManager->getStorage('candidate_registration')->loadMultiple($registration_ids) as $registration) {
      if ($registration instanceof CandidateRegistrationInterface) {
        $event_ids[] = (int) $registration->get('event')->target_id;
      }
    }

    return array_values(array_unique($event_ids));
  }

  /**
   * Returns registrations for a candidate keyed by event id.
   *
   * @return \Drupal\candidate_registration\CandidateRegistrationInterface[]
   *   Registrations keyed by event id.
   */
  public function loadRegistrationsForCandidateByEvent(NodeInterface $candidate, array $event_ids): array {
    if (!$event_ids) {
      return [];
    }

    $ids = $this->entityTypeManager
      ->getStorage('candidate_registration')
      ->getQuery()
      ->condition('candidate', $candidate->id())
      ->condition('event', $event_ids, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $by_event = [];
    foreach ($this->entityTypeManager->getStorage('candidate_registration')->loadMultiple($ids) as $registration) {
      if ($registration instanceof CandidateRegistrationInterface) {
        $by_event[(int) $registration->get('event')->target_id] = $registration;
      }
    }
    return $by_event;
  }

  /**
   * Returns candidate nodes maintained by the given account.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Candidate nodes.
   */
  public function loadCandidatesForMaintainer(AccountInterface $account): array {
    if ($account->isAnonymous()) {
      return [];
    }

    $ids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'candidate')
      ->condition('status', 1)
      ->condition('field_candidate_maintainers', $account->id())
      ->accessCheck(TRUE)
      ->execute();

    return array_filter(
      $this->entityTypeManager->getStorage('node')->loadMultiple($ids),
      static fn ($node): bool => $node instanceof NodeInterface,
    );
  }

  /**
   * Checks whether an account maintains a candidate.
   */
  public function userMaintainsCandidate(NodeInterface $candidate, AccountInterface $account): bool {
    if ($account->isAnonymous() || $candidate->getType() !== 'candidate' || !$candidate->hasField('field_candidate_maintainers')) {
      return FALSE;
    }

    foreach ($candidate->get('field_candidate_maintainers') as $maintainer) {
      if ((int) $maintainer->target_id === (int) $account->id()) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks whether an account can edit the event through existing node access.
   */
  public function userCanEditEvent(NodeInterface $event, AccountInterface $account): bool {
    return $event->access('update', $account);
  }

  /**
   * Access check for candidate registration operations.
   */
  public function access(NodeInterface $event, NodeInterface $candidate, string $operation, AccountInterface $account): AccessResultInterface {
    $result = AccessResult::forbidden()
      ->cachePerUser()
      ->addCacheableDependency($event)
      ->addCacheableDependency($candidate);

    if ($event->getType() !== 'event' || !$event->isPublished() || $candidate->getType() !== 'candidate' || !$candidate->isPublished()) {
      return $result;
    }

    $registration = $this->loadRegistration($event, $candidate);
    $is_maintainer = $this->userMaintainsCandidate($candidate, $account);
    $can_edit_event = $this->userCanEditEvent($event, $account);

    if ($registration) {
      $result->addCacheableDependency($registration);
    }

    $allowed = match ($operation) {
      'add' => $registration === NULL && ($is_maintainer || $can_edit_event),
      'edit', 'delete' => $registration !== NULL && ($is_maintainer || $can_edit_event),
      default => FALSE,
    };

    return $allowed
      ? AccessResult::allowed()->cachePerUser()->addCacheableDependency($event)->addCacheableDependency($candidate)
      : $result;
  }

}

