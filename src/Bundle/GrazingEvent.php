<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Bundle;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\log\Entity\LogInterface;
use Drupal\plan\Entity\PlanRecord;

/**
 * Bundle logic for Grazing Event.
 */
class GrazingEvent extends PlanRecord implements GrazingEventInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function label() {

    // Build label with the referenced plan and log.
    if ($plan = $this->getPlan()) {
      return $this->t('Grazing event: %log - %plan', ['%log' => $this->getLog()->label(), '%plan' => $plan->label()]);
    }

    // Fallback to default.
    return parent::label();
  }

  /**
   * {@inheritdoc}
   */
  public function getLog(): LogInterface {
    return $this->get('log')->referencedEntities()[0];
  }
}
