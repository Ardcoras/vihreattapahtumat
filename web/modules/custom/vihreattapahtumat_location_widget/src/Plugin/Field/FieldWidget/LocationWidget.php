<?php

declare(strict_types=1);

namespace Drupal\vihreattapahtumat_location_widget\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * A smart location autocomplete widget combining search and inline creation.
 *
 * @FieldWidget(
 *   id = "location_autocomplete_widget",
 *   label = @Translation("Location smart autocomplete"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class LocationWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $tid = $items[$delta]->target_id ?? NULL;

    $autocomplete_url = Url::fromRoute('vihreattapahtumat_location_widget.autocomplete')->toString();
    $municipality_url = Url::fromRoute('vihreattapahtumat_location_widget.municipality_autocomplete')->toString();
    $quick_create_url = Url::fromRoute('vihreattapahtumat_location_widget.quick_create')->toString();
    $preview_url      = Url::fromRoute('vihreattapahtumat_location_widget.preview')->toString();
    $csrf_token       = \Drupal::csrfToken()->get('location-quick-create');

    $element['target_id'] = [
      '#type' => 'hidden',
      '#default_value' => $tid ?? '',
      '#attributes' => ['class' => ['location-widget__value']],
    ];

    $attrs = implode(' ', [
      'data-location-widget',
      'data-autocomplete-url="' . htmlspecialchars($autocomplete_url) . '"',
      'data-municipality-url="' . htmlspecialchars($municipality_url) . '"',
      'data-quick-create-url="' . htmlspecialchars($quick_create_url) . '"',
      'data-preview-url="' . htmlspecialchars($preview_url) . '"',
      'data-csrf-token="' . htmlspecialchars($csrf_token) . '"',
      'data-initial-tid="' . (int) $tid . '"',
      'data-has-value="' . ($tid ? '1' : '0') . '"',
    ]);

    $field_name = $this->fieldDefinition->getName();
    $field_type = $this->fieldDefinition->getType();
    $label      = (string) $this->fieldDefinition->getLabel();
    $required   = $this->fieldDefinition->isRequired();

    // Convert snake_case to kebab-case for CSS class names (Drupal convention).
    $css_field = str_replace('_', '-', $field_name);
    $css_type  = str_replace('_', '-', $field_type);

    $outer_class = "field--type-{$css_type} field--name-{$css_field} field--widget-location-autocomplete-widget js-form-wrapper form-wrapper";

    $inner_class = "js-form-item form-item js-form-type-location-autocomplete-widget form-type--location-autocomplete-widget js-form-item-{$css_field}-0 form-item--{$css_field}-0";

    $label_class = 'form-item__label' . ($required ? ' js-form-required form-required' : '');

    $element['#prefix'] = "<div class=\"{$outer_class}\">"
      . "<div class=\"{$inner_class}\">"
      . "<label class=\"{$label_class}\">" . htmlspecialchars($label) . '</label>'
      . "<div class=\"location-widget\" {$attrs}>";

    $element['#suffix'] = '</div></div></div>';

    $element['#attached']['library'][] = 'vihreattapahtumat_location_widget/location_autocomplete';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $result = [];
    foreach ($values as $value) {
      $tid = $value['target_id'] ?? '';
      if ($tid !== '' && is_numeric($tid) && (int) $tid > 0) {
        $result[] = ['target_id' => (int) $tid];
      }
    }
    return $result;
  }

}
