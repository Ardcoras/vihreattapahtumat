<?php

declare(strict_types=1);

namespace Drupal\candidate_registration\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\candidate_registration\CandidateRegistrationManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding or editing a candidate registration note.
 */
final class CandidateRegistrationForm extends FormBase {

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
    return 'candidate_registration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?NodeInterface $candidate = NULL, string $operation = 'add') {
    if (!$node || !$candidate) {
      return $form;
    }

    $registration = $this->manager->loadRegistration($node, $candidate);
    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => $node->id(),
    ];
    $form['candidate_id'] = [
      '#type' => 'hidden',
      '#value' => $candidate->id(),
    ];

    $form['candidate_label'] = [
      '#markup' => '<h4>' . $this->t('Candidate: @candidate', ['@candidate' => $candidate->label()]) . '</h4>',
    ];

    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Note'),
      '#default_value' => $registration ? (string) $registration->get('note')->value : '',
      '#rows' => 2,
      '#maxlength' => 2000,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $registration ? $this->t('Update registration') : $this->t('Register candidate'),
      '#attributes' => [
        'class' => ['btn-secondary'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $event = $this->entityTypeManager->getStorage('node')->load((int) $form_state->getValue('event_id'));
    $candidate = $this->entityTypeManager->getStorage('node')->load((int) $form_state->getValue('candidate_id'));
    if (!$event instanceof NodeInterface || !$candidate instanceof NodeInterface) {
      $form_state->setErrorByName('candidate_id', $this->t('The registration target is invalid.'));
      return;
    }

    $registration = $this->manager->loadRegistration($event, $candidate);
    $operation = $registration ? 'edit' : 'add';
    if (!$this->manager->access($event, $candidate, $operation, $this->currentUser())->isAllowed()) {
      $form_state->setErrorByName('candidate_id', $this->t('You are not allowed to manage this registration.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $event = $this->entityTypeManager->getStorage('node')->load((int) $form_state->getValue('event_id'));
    $candidate = $this->entityTypeManager->getStorage('node')->load((int) $form_state->getValue('candidate_id'));
    if (!$event instanceof NodeInterface || !$candidate instanceof NodeInterface) {
      return;
    }

    $registration = $this->manager->loadRegistration($event, $candidate);
    if (!$registration) {
      $registration = $this->entityTypeManager->getStorage('candidate_registration')->create([
        'event' => $event->id(),
        'candidate' => $candidate->id(),
      ]);
    }

    $registration->set('note', trim((string) $form_state->getValue('note')));
    $registration->save();

    $this->messenger()->addStatus($this->t('Candidate registration saved.'));
    $form_state->setRedirect('entity.node.canonical', ['node' => $event->id()]);
  }

}
