<?php

namespace Drupal\vihreattapahtumat_social_auth\User;

use Drupal\social_auth\User\UserAuthenticator;

/**
 * Prevents anonymous social login from auto-linking by matching email address.
 */
class ManualLinkingUserAuthenticator extends UserAuthenticator {

  /**
   * {@inheritdoc}
   */
  public function authenticateWithEmail(string $email, string $provider_user_id, string $token, ?array $data): bool {
    if ($this->userManager->loadUserByProperty('mail', $email)) {
      $this->messenger->addWarning($this->t('Tällä sähköpostiosoitteella on jo käyttäjätili. Kirjaudu ensin olemassa olevalla tunnuksellasi ja yhdistä Google käyttäjätilisi sivulla.'));
      $this->loggerFactory
        ->get($this->getPluginId())
        ->notice('Social Auth same-email auto-link prevented.');
      $this->response = $this->getLoginFormRedirection();

      return TRUE;
    }

    return FALSE;
  }

}
