<?php

declare(strict_types=1);

namespace Drupal\candidate_registration\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\candidate_registration\CandidateRegistrationInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the candidate registration entity.
 *
 * @ContentEntityType(
 *   id = "candidate_registration",
 *   label = @Translation("Candidate registration"),
 *   label_collection = @Translation("Candidate registrations"),
 *   label_singular = @Translation("candidate registration"),
 *   label_plural = @Translation("candidate registrations"),
 *   label_count = @PluralTranslation(
 *     singular = "@count candidate registration",
 *     plural = "@count candidate registrations"
 *   ),
 *   base_table = "candidate_registration",
 *   admin_permission = "administer candidate registrations",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "uid" = "uid",
 *   }
 * )
 */
final class CandidateRegistration extends ContentEntityBase implements CandidateRegistrationInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values): void {
    parent::preCreate($storage, $values);
    $values += [
      'uid' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setReadOnly(TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setReadOnly(TRUE);

    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Event'))
      ->setSetting('target_type', 'node')
      ->setRequired(TRUE);

    $fields['candidate'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Candidate'))
      ->setSetting('target_type', 'node')
      ->setRequired(TRUE);

    $fields['note'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Note'))
      ->setRequired(FALSE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
