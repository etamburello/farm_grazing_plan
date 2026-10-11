<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Form;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\farm_form\Traits\FarmFormProtectionTrait;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\log\Entity\Log;
use Drupal\plan\Entity\PlanInterface;
use Drupal\plan\Entity\PlanRecord;

/**
 * Grazing plan form.
 */
class GrazingPlanEventsForm extends FormBase {

  use FarmFormProtectionTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GrazingPlanInterface $grazingPlan,
    protected AccessManagerInterface $accessManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_grazing_plan_events_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $plan = NULL) {

    // If a plan is not available, bail.
    if (empty($plan) || !($plan instanceof PlanInterface) || $plan->bundle() != 'grazing') {
      return [
        '#type' => 'markup',
        '#markup' => 'No grazing plan was provided.',
      ];
    }

    // Store the plan ID for use in the submit handlers.
    $form_state->set('plan_id', $plan->id());

    // Build vertical tabs.
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    // Load all grazing events, grouped by asset.
    $grazing_events_by_asset = $this->grazingPlan->getGrazingEventsByAsset($plan);

    // Get the number of new grazing event rows added to each asset's table.
    $new_rows_by_asset = $form_state->get('grazing_event_new_rows');
    if ($new_rows_by_asset === NULL) {
      $new_rows_by_asset = [];
    }

    // Get the new grazing event rows that were removed from each asset's
    // table.
    $removed_rows_by_asset = $form_state->get('grazing_event_removed_rows');
    if ($removed_rows_by_asset === NULL) {
      $removed_rows_by_asset = [];
    }

    // Build the grazing events as a form tree so form state values are built
    // as a nested array.
    $form['grazing_events']['#tree'] = TRUE;

    // For each asset, generate fields for editing each grazing event.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {

      // Load the asset.
      $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);

      // If the user does not have access to this asset, continue to the next.
      if (!$asset->access('view')) {
        continue;
      }

      // Separate the grazing events by their log status.
      $done_events = [];
      $pending_events = [];
      foreach ($grazing_events as $grazing_event_id => $grazing_event) {
        if ($grazing_event->getStatus() === 'done') {
          $done_events[$grazing_event_id] = $grazing_event;
        }
        elseif ($grazing_event->getStatus() === 'pending') {
          $pending_events[$grazing_event_id] = $grazing_event;
        }
      }

      // Create two tables for done and pending grazing events.
      // The pending table is draggable, so the user can reorder the pending
      // grazing events. The tabledrag group class is unique per asset.
      $group = 'grazing-event-order-' . $asset_id;
      $done_table = $this->buildGrazingEventTable($asset_id, 'done', $done_events);
      $pending_table = $this->buildGrazingEventTable($asset_id, 'pending', $pending_events, $group);

      // Add new grazing event rows, if any were added via Ajax. New rows are
      // appended to the end of the pending table, after the existing rows.
      $num_new_rows = $new_rows_by_asset[$asset_id] ?? 0;
      for ($row_num = 1; $row_num <= $num_new_rows; $row_num++) {
        $row_key = 'new_' . $row_num;

        // Skip the row if it was removed via Ajax.
        if (in_array($row_key, $removed_rows_by_asset[$asset_id] ?? [])) {
          continue;
        }

        $defaults = $this->getNewGrazingEventRowDefaults($asset_id, $row_num, $grazing_events, $form_state);
        $defaults['weight'] = count($pending_events) + ($row_num - 1);
        $row = $this->buildGrazingEventRowFields('pending', $defaults, $group, TRUE);

        // Add a button to remove the new row via Ajax. New rows are not
        // saved, so removing the row does not delete any entities.
        $row['operations']['remove'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => 'remove_grazing_event_' . $asset_id . '_' . $row_num,
          '#submit' => [[$this, 'removeGrazingEventRow']],
          // Limit validation errors, so that removing a row does not require
          // the form to be valid.
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => [$this, 'pendingGrazingEventsAjaxCallback'],
            'wrapper' => 'pending-grazing-events-wrapper-' . $asset_id,
          ],
        ];
        $pending_table[$row_key] = $row;
      }

      // Add the tables to a vertical tab for this asset.
      // Only show done events if there are any.
      $form['grazing_events'][$asset_id] = [
        '#type' => 'details',
        '#title' => $asset->label(),
        '#group' => 'tabs',
      ];
      if (!empty($done_events)) {
        $form['grazing_events'][$asset_id]['done'] = $done_table;
        $form['grazing_events'][$asset_id]['done']['#weight'] = -10;
      }
      $form['grazing_events'][$asset_id]['pending'] = $pending_table;

      // Allow overriding the planned start of the next pending event, in case
      // it needs to happen earlier or later than planned, since the planned
      // duration of the last completed event may not reflect reality. Default
      // the date to the first pending event's start, or the last completed
      // event's date + its planned duration, if there are no pending events.
      $form['grazing_events'][$asset_id]['pending_start'] = [
        '#type' => 'details',
        '#title' => $this->t('Next planned movement'),
        '#description' => $this->t('When should the next grazing event start? This will default to the start date of the first pending event, or the date + planned duration of the last completed event if there are no pending events. Adjust this if plans change.'),
        '#open' => FALSE,
        '#weight' => -5,
      ];
      $form['grazing_events'][$asset_id]['pending_start']['date'] = [
        '#type' => 'date',
        '#title' => $this->t('Next planned movement'),
        '#title_display' => 'invisible',
        '#default_value' => $this->getNextPlannedMovementDateDefault($pending_events, $done_events),
        '#required' => TRUE,
      ];

      // Add a submit button to each asset's grazing events.
      $form['grazing_events'][$asset_id]['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save events'),
        '#button_type' => 'primary',
      ];

      // Add a button to add a new grazing event row via Ajax.
      $form['grazing_events'][$asset_id]['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add event'),
        '#name' => 'add_grazing_event_' . $asset_id,
        '#submit' => [[$this, 'addGrazingEventRow']],
        '#ajax' => [
          'callback' => [$this, 'pendingGrazingEventsAjaxCallback'],
          'wrapper' => 'pending-grazing-events-wrapper-' . $asset_id,
        ],
      ];
    }

    // Show a message if there are no grazing events yet.
    if (empty($grazing_events_by_asset)) {
      $form['no_events'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('There are no grazing events in this plan.') . '</p>',
      ];
    }

    // Add a link to the "Add grazing event" form.
    $form['add_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Add a grazing event'),
      '#url' => Url::fromRoute('farm_grazing_plan.add_event', ['plan' => $plan->id()]),
    ];

    // Attach the grazing plan events form CSS library.
    $form['#attached']['library'][] = 'farm_grazing_plan/events_form';

    // Enable form protection.
    $this->enableFormProtection($form);

    return $form;
  }

  /**
   * Build a table of grazing events.
   *
   * @param int $asset_id
   *   The asset ID.
   * @param string $status
   *   The status of the grazing events (done/pending).
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $grazing_events
   *   The grazing events to include in the table.
   * @param string|null $group
   *   The tabledrag group class, for the draggable pending table.
   *
   * @return array
   *   Returns a render array of the table, with one row per grazing event.
   */
  protected function buildGrazingEventTable(int $asset_id, string $status, array $grazing_events, ?string $group = NULL): array {

    // Set the caption based on the status.
    $caption = '';
    if ($status == 'done') {
      $caption = $this->t('Completed events');
    }
    elseif ($status == 'pending') {
      $caption = $this->t('Pending events');
    }

    // Initialize the table with a caption and column headers. The start
    // columns differ by status: completed events show both planned and actual
    // start and duration and planned recovery, while pending events only show
    // planned start, duration, and recovery.
    if ($status == 'done') {
      $headers = [
        $this->t('Location'),
        $this->t('Actual start'),
        $this->t('Actual duration'),
        $this->t('Planned start'),
        $this->t('Planned duration'),
        $this->t('Planned recovery'),
      ];
    }
    else {
      $headers = [
        $this->t('Location'),
        $this->t('Pending start'),
        $this->t('Planned duration'),
        $this->t('Planned recovery'),
      ];
    }
    $table = [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => $headers,
    ];

    // Make the pending table draggable, so the pending grazing events can be
    // reordered.
    if ($status == 'pending') {
      $table['#header'][] = $this->t('Weight');
      $table['#header'][] = $this->t('Operations');
      $table['#tabledrag'] = [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => $group,
        ],
      ];
    }

    // For the completed events table, compute the actual duration of each
    // event as the time between its actual start (log timestamp) and the next
    // completed event's actual start. The events are sorted chronologically.
    // The last completed event has no actual duration.
    $actual_durations = [];
    if ($status == 'done') {
      $done_events = array_values($grazing_events);
      foreach ($done_events as $index => $done_event) {
        if (isset($done_events[$index + 1])) {
          $current_start = $done_event->getLog()->get('timestamp')->value;
          $next_start = $done_events[$index + 1]->getLog()->get('timestamp')->value;
          $actual_durations[$done_event->id()] = ($next_start - $current_start) / 86400;
        }
      }
    }

    // Iterate through the grazing events for this asset.
    $weight = 0;
    foreach ($grazing_events as $grazing_event_id => $grazing_event) {

      // Load the log.
      $log = $grazing_event->getLog();

      // Build the grazing event fields with default values from the grazing
      // event and log. The durations are converted from hours to days.
      $defaults = [
        'location' => $log->get('location')->referencedEntities()[0],
        'planned_start' => $grazing_event->get('start')->value,
        'actual_start' => $log->get('timestamp')->value,
        'actual_duration' => $actual_durations[$grazing_event_id] ?? NULL,
        'planned_duration' => $grazing_event->get('duration')->value / 24,
        'planned_recovery' => !empty($grazing_event->get('recovery')->value) ? $grazing_event->get('recovery')->value / 24 : NULL,
        'weight' => $weight,
      ];

      // Build the row fields.
      $row = $this->buildGrazingEventRowFields($status, $defaults, $group);

      // Add a button to complete the first pending event.
      if ($status == 'pending' && $weight == 0 && $this->accessManager->checkNamedRoute('farm.quick.grazing_event_complete')) {
        $row['operations']['complete'] = [
          '#type' => 'submit',
          '#value' => $this->t('Complete'),
          '#name' => 'complete_grazing_event_' . $asset_id,
          '#submit' => [[$this, 'completeGrazingEvent']],
          // Limit validation errors, so that completing an event does not
          // require the form to be valid.
          '#limit_validation_errors' => [],
        ];
      }

      $table[$grazing_event_id] = $row;
      $weight++;
    }

    // Wrap the table in a div with an ID and class.
    $table['#prefix'] = '<div id="' . $status . '-grazing-events-wrapper-' . $asset_id . '" class="grazing-events-wrapper">';
    $table['#suffix'] = '</div>';

    return $table;
  }

  /**
   * Build form fields for a grazing event row.
   *
   * @param string $status
   *   The status of the grazing events (done/pending).
   * @param array $defaults
   *   The default row values, with keys: location, planned_start,
   *   actual_start, actual_duration, planned_duration, planned_recovery,
   *   weight. The duration and recovery values are in days.
   * @param string|null $group
   *   The tabledrag group class, for the draggable pending table.
   * @param bool $location_editable
   *   Whether the location is editable. The location of a saved grazing
   *   event is not editable, and is displayed as a link instead.
   *
   * @return array
   *   Returns a render array of the row's form fields.
   */
  protected function buildGrazingEventRowFields(string $status, array $defaults = [], ?string $group = NULL, bool $location_editable = FALSE) {
    $fields = [];

    // Mark the pending rows as draggable, and set the row weight.
    if ($status == 'pending') {
      $fields['#attributes']['class'][] = 'draggable';
      $fields['#weight'] = $defaults['weight'] ?? 0;
    }

    // Grazing events that are done cannot be edited.
    if ($status == 'done') {

      // Location.
      /** @var \Drupal\asset\Entity\AssetInterface $location */
      $location = $defaults['location'];
      $fields['location'] = [
        '#type' => 'markup',
        '#markup' => $location->toLink()->toString(),
      ];

      // Actual start.
      $fields['actual_start'] = [
        '#type' => 'markup',
        '#markup' => date('Y-m-d', (int) $defaults['actual_start']),
      ];

      // Actual duration, in days. It is the time until the next completed
      // event, so it is unknown for the last completed event.
      $actual_duration = $defaults['actual_duration'] ?? NULL;
      $fields['actual_duration'] = [
        '#type' => 'markup',
        '#markup' => $actual_duration === NULL ? $this->t('TBD') : $this->t('@duration days', ['@duration' => round((float) $actual_duration, 2)]),
      ];

      // Planned start.
      $fields['planned_start'] = [
        '#type' => 'markup',
        '#markup' => !empty($defaults['planned_start']) ? date('Y-m-d', (int) $defaults['planned_start']) : '',
      ];

      // Planned duration, in days.
      $fields['planned_duration'] = [
        '#type' => 'markup',
        '#markup' => $this->t('@duration days', ['@duration' => round((float) $defaults['planned_duration'], 2)]),
      ];

      // Planned recovery, in days.
      $fields['planned_recovery'] = [
        '#type' => 'markup',
        '#markup' => !empty($defaults['planned_recovery']) ? $this->t('@recovery days', ['@recovery' => round((float) $defaults['planned_recovery'], 2)]) : '',
      ];
    }

    // Grazing events that are pending can be edited.
    elseif ($status == 'pending') {

      // Location. The location of a saved pending grazing event is not
      // editable, and is displayed as a link. The location of a new row can
      // be edited until the row is saved.
      if ($location_editable) {
        $fields['location'] = [
          '#type' => 'entity_autocomplete',
          '#title' => $this->t('Location'),
          '#title_display' => 'hidden',
          '#target_type' => 'asset',
          '#selection_handler' => 'views',
          '#selection_settings' => [
            'view' => [
              'view_name' => 'farm_location_reference',
              'display_name' => 'entity_reference',
              'arguments' => [],
            ],
            'match_operator' => 'CONTAINS',
          ],
          '#maxlength' => 1024,
          '#default_value' => $defaults['location'] ?? NULL,
          '#required' => TRUE,
        ];
      }
      else {
        /** @var \Drupal\asset\Entity\AssetInterface $location */
        $location = $defaults['location'];
        $fields['location'] = [
          '#type' => 'markup',
          '#markup' => $location->toLink()->toString(),
        ];
      }

      // Pending start. The pending start is computed from the previous event
      // (timestamp + duration), so it is not editable. The value is displayed
      // as markup and included as a hidden field for the submit handlers.
      $planned_start = $defaults['planned_start'] ?? NULL;
      $fields['planned_start'] = [
        '#type' => 'hidden',
        '#default_value' => $planned_start,
        '#prefix' => empty($planned_start) ? '' : date('Y-m-d', (int) $planned_start),
      ];

      // Planned duration, in days.
      $fields['planned_duration'] = [
        '#type' => 'number',
        '#title' => $this->t('Duration (days)'),
        '#title_display' => 'hidden',
        '#min' => 1,
        '#max' => 365,
        '#scale' => 1,
        '#default_value' => $defaults['planned_duration'] ?? '',
        '#required' => TRUE,
      ];

      // Planned recovery, in days.
      $fields['planned_recovery'] = [
        '#type' => 'number',
        '#title' => $this->t('Recovery (days)'),
        '#title_display' => 'hidden',
        '#min' => 0,
        '#max' => 365,
        '#scale' => 1,
        '#default_value' => $defaults['planned_recovery'] ?? '',
      ];

      // Weight is used to determine the order of the rows, and is updated by
      // the tabledrag JavaScript when a row is dragged, or via dropdown if
      // dragging is disabled.
      $fields['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight'),
        '#title_display' => 'invisible',
        '#delta' => 100,
        '#default_value' => $defaults['weight'] ?? 0,
        '#attributes' => ['class' => [$group]],
      ];

      // Operations container.
      $fields['operations'] = [
        '#type' => 'container',
      ];
    }

    return $fields;
  }

  /**
   * Submit handler for the "Add event" button.
   *
   * Increments the number of new grazing event rows for the triggering asset.
   */
  public function addGrazingEventRow(array &$form, FormStateInterface $form_state) {

    // Get the asset ID from the triggering element.
    $asset_id = $form_state->getTriggeringElement()['#parents'][1];

    // Increment the number of new grazing event rows for this asset.
    $new_rows_by_asset = $form_state->get('grazing_event_new_rows');
    if ($new_rows_by_asset === NULL) {
      $new_rows_by_asset = [];
    }
    $new_rows_by_asset[$asset_id] = ($new_rows_by_asset[$asset_id] ?? 0) + 1;
    $form_state->set('grazing_event_new_rows', $new_rows_by_asset);

    // Rebuild the form to render the new row.
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "Remove" button on a new grazing event row.
   *
   * Records the row key in the form state, so that the row is skipped when
   * the form is rebuilt. New rows are not saved, so no entities are deleted.
   */
  public function removeGrazingEventRow(array &$form, FormStateInterface $form_state) {

    // Get the asset ID and row key from the triggering element.
    $parents = $form_state->getTriggeringElement()['#parents'];
    $asset_id = $parents[1];
    $row_key = $parents[3];

    // Record the removed row, so that it is skipped when the form is rebuilt.
    $removed_rows_by_asset = $form_state->get('grazing_event_removed_rows');
    if ($removed_rows_by_asset === NULL) {
      $removed_rows_by_asset = [];
    }
    $removed_rows_by_asset[$asset_id][] = $row_key;
    $form_state->set('grazing_event_removed_rows', $removed_rows_by_asset);

    // Rebuild the form to remove the row.
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "Complete" button on the first pending event.
   */
  public function completeGrazingEvent(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('farm.quick.grazing_event_complete');
  }

  /**
   * Ajax callback for the pending grazing events table.
   *
   * Returns the pending table for the triggering asset, so that it is
   * re-rendered after a row is added or removed.
   */
  public function pendingGrazingEventsAjaxCallback(array $form, FormStateInterface $form_state) {
    $asset_id = $form_state->getTriggeringElement()['#parents'][1];
    return $form['grazing_events'][$asset_id]['pending'];
  }

  /**
   * Get the default value for the next planned movement date field.
   *
   * Defaults to the first pending event's planned start, if available.
   * Otherwise, defaults to the last completed event's date + its planned
   * duration.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $pending_events
   *   The asset's pending grazing events, sorted chronologically.
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $done_events
   *   The asset's completed grazing events, sorted chronologically.
   *
   * @return string
   *   Returns the default date, formatted as Y-m-d.
   */
  protected function getNextPlannedMovementDateDefault(array $pending_events, array $done_events): string {

    // Default to the first pending event's planned start, if available.
    $start = !empty($pending_events) ? reset($pending_events)->get('start')->value : NULL;

    // Otherwise, default to the last completed event's date + its planned
    // duration. The events are sorted chronologically, so the last completed
    // event is the most recent.
    if (empty($start)) {
      $start = NULL;
      foreach ($done_events as $grazing_event) {
        $start = $grazing_event->getLog()->get('timestamp')->value + ($grazing_event->get('duration')->value * 60 * 60);
      }
    }

    // Format the timestamp as a Y-m-d date string, for the date form element.
    return empty($start) ? '' : date('Y-m-d', (int) $start);
  }

  /**
   * Get default values for a new grazing event row.
   *
   * @param int|string $asset_id
   *   The asset ID.
   * @param int $row_num
   *   The new row number, starting at 1.
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $grazing_events
   *   The asset's saved grazing events, sorted chronologically.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Returns an array of default values with keys: location, planned_start,
   *   planned_duration, planned_recovery. The duration and recovery values
   *   are in days.
   */
  protected function getNewGrazingEventRowDefaults($asset_id, int $row_num, array $grazing_events, FormStateInterface $form_state) {

    // Start with empty defaults.
    $values = [
      'location' => NULL,
      'planned_start' => NULL,
      'planned_duration' => NULL,
      'planned_recovery' => NULL,
    ];

    // Build default values for the first new row based on the most recent
    // saved grazing event.
    if ($row_num == 1) {
      if (empty($grazing_events)) {
        return $values;
      }
      $grazing_event = end($grazing_events);
      $log = $grazing_event->getLog();
      $values['planned_start'] = $log->get('timestamp')->value;

      // Convert the durations from hours to days.
      $values['planned_duration'] = $grazing_event->get('duration')->value / 24;
      $values['planned_recovery'] = !empty($grazing_event->get('recovery')->value) ? $grazing_event->get('recovery')->value / 24 : NULL;
    }

    // Build the default values for subsequent new rows from the previous new
    // row's values.
    else {
      $previous = $form_state->getValue(['grazing_events', $asset_id, 'pending', 'new_' . ($row_num - 1)]);
      $values['planned_start'] = $previous['planned_start'];
      $values['planned_duration'] = $previous['planned_duration'] ?? NULL;
      $values['planned_recovery'] = $previous['planned_recovery'] ?? NULL;
    }

    // The pending start defaults to the previous pending start plus the
    // previous duration, in days.
    if (!empty($values['planned_duration'])) {
      $values['planned_start'] = $values['planned_start'] + ($values['planned_duration'] * 24 * 60 * 60);
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    // Load the plan. Bail if null.
    /** @var \Drupal\plan\Entity\PlanInterface|null $plan */
    $plan = $this->entityTypeManager->getStorage('plan')->load($form_state->get('plan_id'));
    if (is_null($plan)) {
      return;
    }

    // Validate the next planned movement date for each asset.
    $grazing_events_by_asset = $this->grazingPlan->getGrazingEventsByAsset($plan);
    $grazing_event_values = $form_state->getValue('grazing_events');
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {

      // Skip assets that are not in the form (eg: if the user does not have
      // access to them).
      if (empty($grazing_event_values[$asset_id])) {
        continue;
      }

      // Get the submitted pending start date for this asset's next movement
      // and convert it to a timestamp.
      $timestamp = strtotime((string) ($grazing_event_values[$asset_id]['pending_start']['date']));

      // Validate that the date is not before the start of the asset's last
      // completed event. The events are sorted chronologically, so the last
      // completed event is the most recent.
      $last_done_start = NULL;
      foreach ($grazing_events as $grazing_event) {
        if ($grazing_event->getStatus() === 'done') {
          $last_done_start = $grazing_event->getLog()->get('timestamp')->value;
        }
      }
      if ($last_done_start !== NULL && $timestamp < $last_done_start) {
        $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);
        $form_state->setError($form['grazing_events'][$asset_id]['pending_start']['date'], $this->t('The next planned movement date for @asset cannot be before the start date of its last completed event.', ['@asset' => $asset->label()]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Load the plan. Bail if null.
    /** @var \Drupal\plan\Entity\PlanInterface|null $plan */
    $plan = $this->entityTypeManager->getStorage('plan')->load($form_state->get('plan_id'));
    if (is_null($plan)) {
      return;
    }

    // Iterate through the submitted grazing events for each asset.
    $grazing_event_values_by_asset = $form_state->getValue('grazing_events');
    foreach ($grazing_event_values_by_asset as $asset_id => $grazing_events) {

      // Convert the submitted next planned movement date to a timestamp.
      $anchor_start = (int) strtotime((string) ($grazing_events['pending_start']['date'] ?? ''));

      // Get the pending grazing event rows, and ensure that it is an array.
      // If the pending table has no rows, Form API will return store its value
      // as an empty string.
      $rows = $grazing_events['pending'];
      if (!is_array($rows)) {
        $rows = [];
      }

      // Update and reorder the pending grazing events and recompute their
      // start dates. Completed grazing events are not editable, so they are
      // not updated.
      $this->processPendingGrazingEvents($plan, (int) $asset_id, $rows, $anchor_start);
    }

    // Tell the user that grazing events were updated.
    $this->messenger()->addMessage($this->t('Updated the grazing events.'));
  }

  /**
   * Process the submitted pending grazing events for an asset.
   *
   * Reorders the rows based on the submitted weight values, recomputes the
   * start dates from the next planned movement date, and updates or creates
   * the grazing events and logs.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The grazing plan.
   * @param int $asset_id
   *   The asset ID.
   * @param array $rows
   *   The submitted pending grazing event values, keyed by row key.
   * @param int $anchor_start
   *   The next planned movement date, as a timestamp at midnight.
   */
  protected function processPendingGrazingEvents(PlanInterface $plan, int $asset_id, array $rows, int $anchor_start) {

    // Bail if there are no pending rows.
    if (empty($rows)) {
      return;
    }

    // Sort the submitted rows by their submitted weight values, to get the
    // order of the rows as submitted.
    uasort($rows, function ($a, $b) {
      return ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0));
    });

    // Recompute the start dates from the next planned movement date and the
    // submitted planned durations.
    $this->recomputePendingGrazingEventStarts($anchor_start, $rows);

    // Update the existing grazing events, and create the new ones.
    foreach ($rows as $row_key => $values) {
      if (is_numeric($row_key)) {
        $this->updateGrazingEvent((int) $row_key, $values);
      }
      else {
        $this->createGrazingEvent((int) $plan->id(), $asset_id, $values);
      }
    }
  }

  /**
   * Recompute the start dates of the pending grazing events for an asset.
   *
   * The first event starts at the next planned movement date. Each subsequent
   * event starts at the end of the previous event, based on its planned
   * duration.
   *
   * @param int $anchor_start
   *   The next planned movement date, as a timestamp. The first pending
   *   event starts at this date.
   * @param array $rows
   *   The pending grazing event values, keyed by row key, in the new order.
   *   The values are modified in place.
   */
  protected function recomputePendingGrazingEventStarts(int $anchor_start, array &$rows) {

    // Cascade the start dates through the pending events.
    $previous_start = NULL;
    $previous_duration = 0;
    foreach ($rows as &$values) {

      // Convert duration from days to seconds.
      $duration = (int) round((float) ($values['planned_duration'] ?? 0) * 24 * 60 * 60);

      // The first event starts at the next planned movement date.
      if ($previous_start === NULL) {
        $values['planned_start'] = $anchor_start;
      }

      // Subsequent events start at the end of the previous event.
      else {
        $values['planned_start'] = $previous_start + $previous_duration;
      }

      // Update previous start and duration tracking variables.
      $previous_start = (int) $values['planned_start'];
      $previous_duration = $duration;
    }
  }

  /**
   * Update an existing grazing event.
   *
   * @param int $grazing_event_id
   *   The grazing event ID.
   * @param array $values
   *   An array of values from $form_state.
   */
  protected function updateGrazingEvent(int $grazing_event_id, array $values) {
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event */
    $grazing_event = $this->entityTypeManager->getStorage('plan_record')->load($grazing_event_id);

    // Update the grazing event values, converting the durations from days to
    // hours.
    $grazing_event->set('start', $values['planned_start']);
    $grazing_event->set('duration', (int) round($values['planned_duration'] * 24));
    $grazing_event->set('recovery', empty($values['planned_recovery']) ? NULL : (int) round($values['planned_recovery'] * 24));
    $grazing_event->save();

    // Update the grazing event's log values. The actual start (log timestamp)
    // is kept in sync with the pending start. The location is not editable,
    // so it is not updated.
    $log = $grazing_event->getLog();
    $log->set('timestamp', $values['planned_start']);
    $log->save();
  }

  /**
   * Create a new grazing event.
   *
   * @param int $plan_id
   *   The plan ID.
   * @param int $asset_id
   *   The asset ID.
   * @param array $values
   *   An array of values from $form_state.
   */
  protected function createGrazingEvent(int $plan_id, int $asset_id, array $values) {

    // Load the asset and location.
    $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);
    $location = $this->entityTypeManager->getStorage('asset')->load($values['location']);

    // Create the movement log.
    $log = Log::create([
      'type' => 'activity',
      'name' => $this->t('Move @asset to @location', ['@asset' => $asset->label(), '@location' => $location->label()]),
      'timestamp' => $values['planned_start'],
      'asset' => [$asset],
      'location' => [$location],
      'status' => 'pending',
      'is_movement' => TRUE,
    ]);
    $log->save();

    // Create the grazing event, converting the durations from days to hours.
    $grazing_event = PlanRecord::create([
      'type' => 'grazing_event',
      'plan' => $plan_id,
      'log' => $log->id(),
      'start' => $values['planned_start'],
      'duration' => (int) round($values['planned_duration'] * 24),
      'recovery' => empty($values['planned_recovery']) ? NULL : (int) round($values['planned_recovery'] * 24),
    ]);
    $grazing_event->save();
  }

}
