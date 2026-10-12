<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_quick\Kernel\QuickFormTestBase;
use Drupal\farm_quick\Form\QuickForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for the grazing event complete quick form.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class QuickGrazingEventCompleteTest extends QuickFormTestBase {

  use MockGrazingPlanEntitiesTrait;

  /**
   * Quick form ID.
   *
   * @var string
   */
  protected $quickFormId = 'grazing_event_complete';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_activity',
    'farm_animal',
    'farm_animal_type',
    'farm_grazing_plan',
    'farm_id_tag',
    'farm_land',
    'plan',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('plan');
    $this->installEntitySchema('plan_record');
    $this->installConfig([
      'farm_activity',
      'farm_animal',
      'farm_animal_type',
      'farm_grazing_plan',
      'farm_land',
    ]);
  }

  /**
   * Test the grazing event complete quick form.
   */
  public function testQuickGrazingEventComplete() {

    // Load the form and confirm that the only option in the dropdown is the
    // empty "- Select -" option.
    $form = \Drupal::service('form_builder')->getForm(QuickForm::class, $this->quickFormId);
    $options = $form['grazing_event']['#options'];
    $this->assertCount(1, $options);
    $this->assertArrayHasKey('', $options);

    // Create mock plan entities. These will have a status of "planning" by
    // default.
    $this->createMockPlanEntities();

    // Load the form and confirm that we still only see the empty "- Select -"
    // option.
    $form = \Drupal::service('form_builder')->getForm(QuickForm::class, $this->quickFormId);
    $options = $form['grazing_event']['#options'];
    $this->assertCount(1, $options);
    $this->assertArrayHasKey('', $options);

    // Load all grazing plans.
    /** @var \Drupal\plan\Entity\PlanInterface[] $plans */
    $plans = \Drupal::entityTypeManager()->getStorage('plan')->loadByProperties(['type' => 'grazing']);

    // Set the status of all plans to "active".
    foreach ($plans as $plan) {
      $plan->set('status', 'active')->save();
    }

    // Load the form and confirm that we see one pending event option per asset
    // per plan.
    $form = \Drupal::service('form_builder')->getForm(QuickForm::class, $this->quickFormId);
    $options = $form['grazing_event']['#options'];
    $this->assertCount(3, $options);
    $this->assertArrayHasKey('', $options);

    // Without a query parameter, no grazing event is preselected.
    $this->assertNull($form['grazing_event']['#default_value']);

    // Get the IDs of the pending grazing event options.
    $option_ids = array_filter(array_keys($options), function ($key) {
      return $key !== '';
    });

    // For each pending grazing event, confirm that the query parameter
    // preselects the corresponding option in the dropdown.
    foreach ($option_ids as $event_id) {
      \Drupal::request()->query->set('grazing_event', $event_id);
      $form = \Drupal::service('form_builder')->getForm(QuickForm::class, $this->quickFormId);
      $this->assertEquals($event_id, $form['grazing_event']['#default_value']);
      \Drupal::request()->query->remove('grazing_event');
    }

    // Confirm that an unknown grazing event ID does not preselect any option.
    \Drupal::request()->query->set('grazing_event', 999999);
    $form = \Drupal::service('form_builder')->getForm(QuickForm::class, $this->quickFormId);
    $this->assertNull($form['grazing_event']['#default_value']);
    \Drupal::request()->query->remove('grazing_event');

    // Complete the first pending grazing event and confirm its log is marked
    // as done.
    $first_event_id = array_key_first(array_filter($options, function ($value, $key) {
      return $key !== '';
    }, ARRAY_FILTER_USE_BOTH));
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEvent $grazing_event */
    $grazing_event = \Drupal::entityTypeManager()->getStorage('plan_record')->load($first_event_id);
    $log_id = $grazing_event->getLog()->id();
    $this->assertEquals('pending', $grazing_event->getLog()->get('status')->value);
    $this->submitQuickForm(['grazing_event' => $first_event_id]);
    $log = \Drupal::entityTypeManager()->getStorage('log')->load($log_id);
    $this->assertEquals('done', $log->get('status')->value);

    // Confirm the quick form now offers the remaining pending grazing event,
    // and complete it.
    $form = \Drupal::service('form_builder')->getForm(QuickForm::class, $this->quickFormId);
    $option_ids = array_keys($form['grazing_event']['#options']);
    $this->assertCount(2, $option_ids);
    $remaining_event_id = array_pop($option_ids);
    $this->submitQuickForm(['grazing_event' => $remaining_event_id]);

    // Confirm the quick form is back to only showing the empty '- Select -'
    // option.
    $form = \Drupal::service('form_builder')->getForm(QuickForm::class, $this->quickFormId);
    $options = $form['grazing_event']['#options'];
    $this->assertCount(1, $options);
    $this->assertArrayHasKey('', $options);
  }

}
