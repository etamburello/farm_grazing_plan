<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a form for abandoning a grazing event.
 */
class GrazingPlanAbandonEventForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'grazing_plan_abandon_event_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to abandon this grazing event?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $grazing_event = $this->getRouteMatch()->getParameter('grazing_event');
    return $this->t('This will mark the movement log of grazing event "%grazing_event" as "abandoned", so it no longer shows in the plan. No data will be deleted.', ['%grazing_event' => $grazing_event->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->getGrazingPlanUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array|RedirectResponse {
    $form = parent::buildForm($form, $form_state);

    // Optionally provide a reason for abandoning.
    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#description' => $this->t("Optionally provide a reason for abandoning this grazing event. This will be saved to the grazing event log's revision message."),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Load the grazing event, plan, and log entities.
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event */
    $grazing_event = $this->getRouteMatch()->getParameter('grazing_event');
    $plan = $grazing_event->getPlan();
    $log = $grazing_event->getLog();

    // Set the status to "abandoned".
    $log->set('status', 'abandoned');

    // Set a revision message.
    // Append the reason, if one was provided.
    $revision_message = $this->t('Grazing event abandoned from plan: <a href=":plan_url">@plan_label</a>', [':plan_url' => $plan->toUrl()->toString(), '@plan_label' => $plan->label()])->render();
    if ($form_state->hasValue('reason')) {
      $revision_message .= ' ' . $this->t('Reason: @reason', ['@reason' => $form_state->getValue('reason')]);
    }
    $log->setNewRevision(TRUE);
    $log->setRevisionLogMessage($revision_message);

    // Save the log.
    $log->save();

    // Tell the user that the grazing event was abandoned.
    $this->messenger()->addMessage($this->t('Abandoned grazing event: %label', ['%label' => $grazing_event->label()]));

    // Redirect to the grazing plan.
    $form_state->setRedirectUrl($this->getGrazingPlanUrl());
  }

  /**
   * Get the grazing plan route URL.
   *
   * @return \Drupal\Core\Url
   *   The Url object.
   */
  protected function getGrazingPlanUrl(): Url {
    $plan = $this->getRouteMatch()->getParameter('plan');
    return Url::fromRoute('entity.plan.canonical', ['plan' => $plan->id()]);
  }

}
