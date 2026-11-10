<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\QuickForm;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\asset\Entity\AssetInterface;
use Drupal\farm_grazing_plan\Bundle\GrazingEventInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\farm_quick\Attribute\QuickForm;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickLogTrait;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Complete grazing event quick form.
 */
#[QuickForm(
  id: 'grazing_event_complete',
  label: new TranslatableMarkup('Complete grazing event'),
  description: new TranslatableMarkup('Complete a pending grazing event in a grazing plan.'),
  helpText: new TranslatableMarkup('Use this form to mark a pending grazing event as complete.'),
  permissions: [
    'update any activity log',
    'create observation log',
  ],
)]
class GrazingEventComplete extends QuickFormBase {

  use QuickLogTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected GrazingPlanInterface $grazingPlan,
    protected RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Get the options for the grazing event select element.
    $options = $this->getGrazingEventOptions();

    // If a grazing event is specified in the query string, preselect it, as
    // long as it is one of the available options.
    $grazing_event_id = (int) $this->requestStack->getCurrentRequest()->query->get('grazing_event');
    $default = array_key_exists($grazing_event_id, $options) ? $grazing_event_id : NULL;

    // Select the grazing event to complete.
    $form['grazing_event'] = [
      '#type' => 'select',
      '#title' => $this->t('Grazing event'),
      '#description' => $this->t('Select a pending grazing event. Only events from "active" plans are included.'),
      '#options' => $options,
      '#default_value' => $default,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Get the options for the grazing event select element.
   *
   * The options are keyed by the grazing event ID.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Returns the option labels, keyed by grazing event ID.
   */
  protected function getGrazingEventOptions(): array {
    $options = [];

    // Load all grazing plans with a status of "active".
    /** @var \Drupal\plan\Entity\PlanInterface[] $plans */
    $plans = $this->entityTypeManager->getStorage('plan')->loadByProperties([
      'type' => 'grazing',
      'status' => 'active',
    ]);

    // Iterate through the plans.
    foreach ($plans as $plan) {

      // If the user does not have access to update this plan, skip it.
      if (!$plan->access('update')) {
        continue;
      }

      // Iterate through each asset's grazing events.
      foreach ($this->grazingPlan->getGrazingEventsByAsset($plan) as $asset_id => $grazing_events) {

        // Load the asset.
        /** @var \Drupal\asset\Entity\AssetInterface $asset */
        $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);

        // If the user does not have access to this asset, continue to the
        // next.
        if (!$asset->access('view')) {
          continue;
        }

        // Iterate through grazing events.
        foreach ($grazing_events as $grazing_event) {

          // If the grazing event is not pending, skip it.
          if ($grazing_event->getStatus() !== 'pending') {
            continue;
          }

          // Build the option label, keeping the start value for sorting.
          $options[$grazing_event->id()] = [
            'start' => (int) $grazing_event->get('start')->value,
            'label' => $grazing_event->label(),
          ];

          // Only include the first pending event.
          break;
        }
      }
    }

    // Sort the options by their planned start dates.
    uasort($options, function ($a, $b) {
      return $a['start'] <=> $b['start'];
    });

    // Return the list of options (just the labels, keyed by grazing event ID).
    return array_map(function ($option) {
      return $option['label'];
    }, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Load the selected grazing event.
    $grazing_event = $this->loadPendingGrazingEvent($form_state->getValue('grazing_event'));

    // If the selected grazing event is missing or not pending, show an error.
    if (!$grazing_event) {
      $this->messenger()->addError($this->t('The selected grazing event does not exist or is not pending.'));
      return;
    }

    // Get the plan and log.
    $plan = $grazing_event->getPlan();
    $log = $grazing_event->getLog();

    // If the user does not have access to update the plan or the log, show an
    // error.
    if (!$plan->access('update') || !$log->access('update')) {
      $this->messenger()->addError($this->t('You do not have access to the selected grazing event.'));
      return;
    }

    // Determine the actual timestamp from submitted form values. Fall back to
    // "now" when no date is provided.
    $date = $form_state->getValue('date');
    $timestamp = $date instanceof DrupalDateTime ? $date->getTimestamp() : time();

    // Update the movement log with completion data. Planned start on the
    // grazing event plan record is intentionally left unchanged.
    $log->set('status', 'done');
    $log->set('timestamp', $timestamp);
    if ($form_state->hasValue('notes')) {
      $log->set('notes', $form_state->getValue('notes'));
    }
    if ($form_state->hasValue('geometry')) {
      $log->set('geometry', $form_state->getValue('geometry'));
    }
    $log->setNewRevision(TRUE);
    $log->setRevisionLogMessage($this->t('Grazing event completed via quick form.')->render());
    $log->save();

    // Create optional observation logs for previous and next paddocks when
    // observation values are submitted with the form.
    $paddocks = $this->getAdjacentPaddocks($grazing_event);
    $observations = $form_state->getValue('observations') ?? [];
    foreach (['previous', 'next'] as $key) {
      $paddock = $paddocks[$key] ?? NULL;
      if (!$paddock || empty($observations[$key])) {
        continue;
      }
      $this->createPaddockObservationLog(
        $paddock,
        $grazing_event->getAsset(),
        $timestamp,
        $observations[$key],
        $key,
      );
    }

    // Tell the user that the grazing event was completed.
    $this->messenger()->addMessage($this->t('Completed grazing event: %label', ['%label' => $grazing_event->label()]));

    // Redirect to the plan.
    $form_state->setRedirect('entity.plan.canonical', ['plan' => $plan->id()]);
  }

  /**
   * Load a pending grazing event by ID.
   *
   * @param int|string|null $grazing_event_id
   *   The grazing event ID.
   *
   * @return \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface|null
   *   The grazing event, or NULL if missing/not pending.
   */
  protected function loadPendingGrazingEvent(int|string|null $grazing_event_id): ?GrazingEventInterface {
    if (empty($grazing_event_id)) {
      return NULL;
    }

    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface|null $grazing_event */
    $grazing_event = $this->entityTypeManager->getStorage('plan_record')->load($grazing_event_id);
    if (!$grazing_event || $grazing_event->bundle() !== 'grazing_event' || $grazing_event->getStatus() !== 'pending') {
      return NULL;
    }
    return $grazing_event;
  }

  /**
   * Get previous and next paddock assets for a grazing event.
   *
   * Previous is the location of the prior event for the same asset in the
   * plan. Next is the location of the selected event (paddock being entered).
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event
   *   The grazing event being completed.
   *
   * @return array{previous: ?\Drupal\asset\Entity\AssetInterface, next: \Drupal\asset\Entity\AssetInterface}
   *   Adjacent paddock assets.
   */
  protected function getAdjacentPaddocks(GrazingEventInterface $grazing_event): array {
    $previous = NULL;
    $next = $grazing_event->getLocation();
    $events = array_values($this->grazingPlan->getGrazingEventsByAsset($grazing_event->getPlan())[$grazing_event->getAsset()->id()] ?? []);
    foreach ($events as $index => $event) {
      if ((int) $event->id() === (int) $grazing_event->id()) {
        if ($index > 0) {
          $previous = $events[$index - 1]->getLocation();
        }
        break;
      }
    }
    return [
      'previous' => $previous,
      'next' => $next,
    ];
  }

  /**
   * Create an observation log for a paddock.
   *
   * Stores paddock check-in/out observation data, including optional photos
   * and quantity measurements, in the farmOS database.
   *
   * @param \Drupal\asset\Entity\AssetInterface $paddock
   *   The paddock (location) asset.
   * @param \Drupal\asset\Entity\AssetInterface $animal_asset
   *   The animal/group asset from the grazing event.
   * @param int $timestamp
   *   The observation timestamp.
   * @param array $values
   *   Submitted observation values for one paddock.
   * @param string $key
   *   Either "previous" or "next".
   */
  protected function createPaddockObservationLog(AssetInterface $paddock, AssetInterface $animal_asset, int $timestamp, array $values, string $key): void {
    $quantities = [];
    foreach ($values['quantities'] ?? [] as $quantity) {
      if ($quantity['value'] === '' || $quantity['value'] === NULL) {
        continue;
      }
      $quantities[] = [
        'type' => 'standard',
        'label' => $quantity['label'] ?? '',
        'measure' => $quantity['measure'] ?? 'length',
        'value' => $quantity['value'],
        'units' => !empty($quantity['units']) ? $quantity['units'] : 'cm',
      ];
    }

    $images = $this->prepareUploadedImages($values['photos'] ?? []);

    // Skip creating an empty observation log when the user did not provide
    // photos, quantities, or notes.
    $notes = $values['notes'] ?? NULL;
    $has_notes = is_array($notes) ? !empty($notes['value']) : !empty($notes);
    if (empty($quantities) && empty($images) && !$has_notes) {
      return;
    }

    $name = $key === 'previous'
      ? $this->t('Previous paddock observation: @paddock', ['@paddock' => Markup::create($paddock->label())])
      : $this->t('Next paddock observation: @paddock', ['@paddock' => Markup::create($paddock->label())]);

    $log_values = [
      'type' => 'observation',
      'name' => $name,
      'timestamp' => $timestamp,
      'asset' => [$paddock, $animal_asset],
      'location' => [$paddock],
      'status' => 'done',
    ];
    if ($has_notes) {
      $log_values['notes'] = $notes;
    }
    if (!empty($quantities)) {
      $log_values['quantity'] = $quantities;
    }
    if (!empty($images)) {
      $log_values['image'] = $images;
    }

    $this->createLog($log_values);
  }

  /**
   * Make uploaded image files permanent and return file IDs for log fields.
   *
   * @param array $fids
   *   File IDs from a managed_file element.
   *
   * @return int[]
   *   Permanent file IDs.
   */
  protected function prepareUploadedImages(array $fids): array {
    $images = [];
    foreach ($fids as $fid) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if (!$file instanceof FileInterface) {
        continue;
      }
      $file->setPermanent();
      $file->save();
      $images[] = (int) $file->id();
    }
    return $images;
  }

}
