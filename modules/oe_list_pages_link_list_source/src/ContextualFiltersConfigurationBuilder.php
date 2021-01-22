<?php

declare(strict_types = 1);

namespace Drupal\oe_list_pages_link_list_source;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\oe_list_pages\FilterConfigurationFormBuilderBase;
use Drupal\oe_list_pages\ListPresetFilter;
use Drupal\oe_list_pages\ListSourceInterface;
use Drupal\oe_list_pages\Plugin\facets\widget\MultiselectWidget;

/**
 * Builds the list page source contextual filters form elements.
 */
class ContextualFiltersConfigurationBuilder extends FilterConfigurationFormBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function getAjaxWrapperId(array $form): string {
    return 'list-page-contextual-filter-values-' . ($form['#parents'] ? '-' . implode('-', $form['#parents']) : '');
  }

  /**
   * {@inheritdoc}
   */
  protected static function getFilterType(): string {
    return 'contextual';
  }

  /**
   * Builds the form for adding/editing/removing contextual filter values.
   *
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   * @param \Drupal\oe_list_pages\ListSourceInterface $list_source
   *   The list source.
   * @param array $configuration
   *   The configuration.
   *
   * @return array
   *   The form elements.
   */
  public function buildContextualFilters(array $form, FormStateInterface $form_state, ListSourceInterface $list_source, array $configuration = []): array {
    $ajax_wrapper_id = $this->getAjaxWrapperId($form);

    $form['wrapper'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'id' => $ajax_wrapper_id,
      ],
    ];

    $form['wrapper']['label'] = [
      '#title' => $this->t('Contextual filter values'),
      '#type' => 'label',
    ];

    $this->initializeCurrentContextualFilterValues($form_state, $configuration, $list_source);
    $current_filters = static::getCurrentValues($form_state, $list_source);
    // Set the current filters on the form so they can be used in the submit.
    $form['current_filters'] = [
      '#type' => 'value',
      '#value' => $current_filters,
    ];

    $facet_id = $form_state->get('contextual_facet_id');

    // If we could not determine a facet ID, we default to showing the summary
    // of default values.
    if (!$facet_id) {
      return $this->buildSummaryPresetFilters($form, $form_state, $list_source, $this->getAvailableFilters($list_source));
    }

    $filter_id = $form_state->get('contextual_filter_id');
    if (!isset($filter_id)) {
      $filter_id = static::generateFilterId($facet_id, array_keys($current_filters));
    }

    $form = $this->buildEditPresetFilter($form, $form_state, $facet_id, $filter_id, $list_source);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildSummaryPresetFilters(array $form, FormStateInterface $form_state, ListSourceInterface $list_source, array $available_filters = []): array {
    $form = parent::buildSummaryPresetFilters($form, $form_state, $list_source, $available_filters);

    $form['wrapper']['summary']['table']['#header'][1]['data'] = $this->t('Operator');
    foreach ($form['wrapper']['summary']['table']['#rows'] as $key => &$row) {
      $row[1]['data'] = str_replace(': ', '', $row[1]['data']);
    }

    return $form;
  }

  /**
   * Builds the edit filter section.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $facet_id
   *   The facet ID.
   * @param string $filter_id
   *   The filter ID.
   * @param \Drupal\oe_list_pages\ListSourceInterface $list_source
   *   The list source.
   *
   * @return array
   *   The built form.
   */
  protected function buildEditPresetFilter(array $form, FormStateInterface $form_state, string $facet_id, string $filter_id, ListSourceInterface $list_source): array {
    $ajax_wrapper_id = $this->getAjaxWrapperId($form);

    $current_filters = static::getCurrentValues($form_state, $list_source);
    $available_filters = $this->getAvailableFilters($list_source);

    $form['wrapper']['edit'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Set operator for :filter', [':filter' => $available_filters[$facet_id]]),
    ];

    // Store the filter IDs on the form state in case we need to rebuild the
    // form.
    $form_state->set(static::getFilterType() . '_filter_id', $filter_id);

    $facet = $this->getFacetById($list_source, $facet_id);
    if (!empty($facet) && ($widget = $facet->getWidgetInstance()) && ($widget instanceof MultiselectWidget)) {
      $filter = NULL;
      if (!empty($current_filters[$filter_id])) {
        $filter = $current_filters[$filter_id];
      }

      $ajax_definition = [
        'callback' => [$this, 'setOperatorAjax'],
        'wrapper' => $ajax_wrapper_id,
      ];

      $form['wrapper']['edit'][$filter_id] = [
        '#parents' => array_merge($form['#parents'], [
          'wrapper',
          'edit',
          $filter_id,
        ]),
        '#tree' => TRUE,
      ];

      $form['wrapper']['edit'][$filter_id]['operator'] = [
        '#type' => 'select',
        '#default_value' => $filter ? $filter->getOperator() : ListPresetFilter::OR_OPERATOR,
        '#options' => ListPresetFilter::getOperators(),
        '#title' => $this->t('Operator'),
      ];

      $form['wrapper']['edit'][$filter_id]['set_value'] = [
        '#value' => $this->t('Set operator'),
        '#type' => 'button',
        '#op' => 'set-operator',
        '#limit_validation_errors' => [
          array_merge($form['#parents'], ['wrapper', 'edit']),
        ],
        '#ajax' => $ajax_definition,
        '#filter_id' => $filter_id,
        '#facet_id' => $facet_id,
        '#executes_submit_callback' => TRUE,
        '#submit' => [[$this, 'setOperatorSubmit']],
      ];

      $form['wrapper']['edit'][$filter_id]['cancel_value'] = [
        '#value' => $this->t('Cancel'),
        '#type' => 'button',
        '#op' => 'cancel-contextual-filter',
        '#name' => static::getFilterType() . '-cancel-' . $filter_id,
        '#limit_validation_errors' => [
          array_merge($form['#parents'], [
            'wrapper',
            'edit',
            $filter_id,
            'cancel_value',
          ]),
        ],
        '#ajax' => $ajax_definition,
        '#executes_submit_callback' => TRUE,
        '#submit' => [[$this, 'cancelValueSubmit']],
      ];
    }

    return $form;
  }

  /**
   * Initialize the form state with the values of the current list source.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $configuration
   *   The current plugin configuration.
   * @param \Drupal\oe_list_pages\ListSourceInterface $list_source
   *   The list source.
   */
  protected function initializeCurrentContextualFilterValues(FormStateInterface $form_state, array $configuration, ListSourceInterface $list_source): void {
    // If we have current values for this list source, we can keep them going
    // forward.
    $values = static::getCurrentValues($form_state, $list_source);
    if ($values) {
      return;
    }

    // Otherwise, we need to check if the current list source matches the
    // passed configuration and set the ones from the configuration if they do.
    // We also check if the values have not been emptied in the current
    // "session".
    if ($list_source->getEntityType() === $configuration['entity_type'] && $list_source->getBundle() === $configuration['bundle'] && !static::areCurrentValuesEmpty($form_state, $list_source)) {
      $values = $configuration['contextual_filters'] ?? [];
      static::setCurrentValues($form_state, $list_source, $values);
      return;
    }

    static::setCurrentValues($form_state, $list_source, []);
  }

  /**
   * Ajax request handler for setting a default value for a filters.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form element.
   */
  public function setOperatorAjax(array &$form, FormStateInterface $form_state): array {
    $triggering_element = $form_state->getTriggeringElement();
    $element = NestedArray::getValue($form, array_slice($triggering_element['#array_parents'], 0, -4));
    return $element['wrapper'];
  }

  /**
   * Submit callback for setting a default value for a filters.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function setOperatorSubmit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\oe_list_pages\ListSourceInterface $list_source */
    $list_source = $form_state->get('list_source');
    $current_filters = static::getCurrentValues($form_state, $list_source);
    $triggering_element = $form_state->getTriggeringElement();
    $facet_id = $triggering_element['#facet_id'];
    $filter_id = $triggering_element['#filter_id'];

    if (!$facet_id) {
      return;
    }

    $parents = array_splice($triggering_element['#parents'], 0, -1);
    $operator = $form_state->getValue(array_merge($parents, ['operator']));

    $current_filters[$filter_id] = new ListPresetFilter($facet_id, [], $operator);

    // Set the current filters on the form state so they can be used elsewhere.
    static::setCurrentValues($form_state, $list_source, $current_filters);
    $form_state->set('contextual_facet_id', NULL);
    $form_state->set('contextual_filter_id', NULL);
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFilterValueSubmit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\oe_list_pages\ListSourceInterface $list_source */
    $list_source = $form_state->get('list_source');
    $current_filters = static::getCurrentValues($form_state, $list_source);
    $triggering_element = $form_state->getTriggeringElement();
    $facet_id = $triggering_element['#facet_id'];
    $filter_id = $triggering_element['#filter_id'];

    if (!$facet_id) {
      return;
    }

    unset($current_filters[$filter_id]);
    static::setCurrentValues($form_state, $list_source, $current_filters);
    $form_state->set('contextual_facet_id', NULL);
    $form_state->set('contextual_filter_id', NULL);

    $form_state->setRebuild(TRUE);
  }

  /**
   * Returns the available facet options the user can choose from.
   *
   * These are only the ones that use a Multiselect widget.
   *
   * @param \Drupal\oe_list_pages\ListSourceInterface $listSource
   *   The list source.
   *
   * @return array
   *   The options.
   */
  protected function getAvailableFilters(ListSourceInterface $listSource): array {
    $facets = $this->facetsManager->getFacetsByFacetSourceId($listSource->getSearchId());
    $options = [];

    foreach ($facets as $facet) {
      $widget = $facet->getWidgetInstance();
      if (!$widget instanceof MultiselectWidget) {
        continue;
      }

      $options[$facet->id()] = $facet->label();
    }

    return $options;
  }

}
