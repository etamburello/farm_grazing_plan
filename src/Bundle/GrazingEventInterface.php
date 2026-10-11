<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Bundle;

use Drupal\log\Entity\LogInterface;
use Drupal\plan\Entity\PlanRecordInterface;

/**
 * Bundle logic for Grazing Event.
 */
interface GrazingEventInterface extends PlanRecordInterface {

  /**
   * Returns the grazing event's movement log.
   *
   * @return \Drupal\log\Entity\LogInterface
   *   The log entity.
   */
  public function getLog(): LogInterface;

}
