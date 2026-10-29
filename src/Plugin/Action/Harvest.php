<?php

declare(strict_types=1)

namespace Drupal\farm_quick_harvest\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\farm_quick\Plugin\Action\QuickFormActionBase;

/**
 * Action for recording harvests.
 */
#[Action(
  id: 'harvest',
  action_label: new TranslatableMarkup('Record harvest'),
  type: 'asset',
  confirm_form_route_name: 'farm.quick.harvest',
)]
class Harvest extends QuickFormActionBase {

  /**
   * {@inheritdoc}
   */
  public function getQuickFormId(): string {
    return 'harvest';
  }

}
