<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Bundle;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\asset\Entity\AssetInterface;
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
    return $this->t('Grazing event: %log - %plan', ['%log' => $this->getLog()->label(), '%plan' => $this->getPlan()->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getLog(): LogInterface {
    return $this->get('log')->referencedEntities()[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getAsset(): AssetInterface {
    return $this->getLog()->get('asset')->referencedEntities()[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getLocation(): AssetInterface {
    return $this->getLog()->get('location')->referencedEntities()[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return $this->getLog()->get('status')->value;
  }

}
