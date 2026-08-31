<?php

declare(strict_types=1);

namespace Drupal\candidate_registration\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\candidate_registration\CandidateRegistrationManager;
use Drupal\node\NodeInterface;

/**
 * Access checker for candidate registration routes.
 */
final class RegistrationAccessCheck implements AccessInterface {

  public function __construct(
    private readonly CandidateRegistrationManager $manager,
  ) {
  }

  /**
   * Checks candidate registration route access.
   */
  public function access(NodeInterface $node, NodeInterface $candidate, AccountInterface $account, string $_candidate_registration_access): AccessResultInterface {
    return $this->manager->access($node, $candidate, $_candidate_registration_access, $account);
  }

}

