<?php

declare(strict_types=1);

namespace Drupal\candidate_registration\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\candidate_registration\CandidateRegistrationManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for removing a candidate registration.
 */
final class CandidateRegistrationDeleteForm extends ConfirmFormBase {

  private ?NodeInterface $event = NULL;

  private ?NodeInterface $candidate = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CandidateRegistrationManager $manager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('candidate_registration.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'candidate_registration_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Remove @candidate from this event?', [
      '@candidate' => $this->candidate?->label() ?? '',
    ])->render();
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->event instanceof NodeInterface
      ? $this->event->toUrl()
      : Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Remove registration')->render();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?NodeInterface $candidate = NULL) {
    $this->event = $node;
    $this->candidate = $candidate;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->event || !$this->candidate) {
      return;
    }

    $registration = $this->manager->loadRegistration($this->event, $this->candidate);
    if ($registration) {
      $registration->delete();
      $this->messenger()->addStatus($this->t('Candidate registration removed.'));
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $this->event->id()]);
  }

}
