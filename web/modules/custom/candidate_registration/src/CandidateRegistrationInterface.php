<?php

declare(strict_types=1);

namespace Drupal\candidate_registration;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for candidate registration entities.
 */
interface CandidateRegistrationInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {
}

