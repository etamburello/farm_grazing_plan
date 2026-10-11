<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\plan\Entity\PlanInterface;

/**
 * Grazing plan logic.
 */
class GrazingPlan implements GrazingPlanInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getGrazingEvents(PlanInterface $plan): array {
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $grazing_events */
    $grazing_events = $this->entityTypeManager->getStorage('plan_record')->loadByProperties(['plan' => $plan->id(), 'type' => 'grazing_event']);
    return $this->sortGrazingEvents($grazing_events);
  }

  /**
   * Sort grazing events chronologically.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $grazing_events
   *   The grazing events.
   *
   * @return \Drupal\farm_grazing_plan\Bundle\GrazingEvent[]
   *   Returns the sorted grazing events.
   */
  protected function sortGrazingEvents(array $grazing_events): array {
    uasort($grazing_events, function ($a, $b) {
      $a_start = $a->get('start')->value;
      $b_start = $b->get('start')->value;
      if ($a_start == $b_start) {
        return 0;
      }
      return ($a_start < $b_start) ? -1 : 1;
    });
    return $grazing_events;
  }

  /**
   * {@inheritdoc}
   */
  public function getGrazingEventsByAsset(PlanInterface $plan): array {
    $grazing_events_by_asset = [];
    $grazing_events = $this->getGrazingEvents($plan);
    foreach ($grazing_events as $grazing_event) {
      $grazing_events_by_asset[$grazing_event->getAsset()->id()][$grazing_event->id()] = $grazing_event;
    }
    return $grazing_events_by_asset;
  }

  /**
   * {@inheritdoc}
   */
  public function getGrazingEventsByLocation(PlanInterface $plan): array {
    $grazing_events_by_location = [];
    $grazing_events = $this->getGrazingEvents($plan);
    foreach ($grazing_events as $grazing_event) {
      $grazing_events_by_location[$grazing_event->getLocation()->id()][$grazing_event->id()] = $grazing_event;
    }
    return $grazing_events_by_location;
  }

}
