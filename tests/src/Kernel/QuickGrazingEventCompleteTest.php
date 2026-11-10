<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\Core\Datetime\DrupalDateTime;
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
    'farm_observation',
    'farm_quantity_standard',
    'file',
    'image',
    'plan',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('plan');
    $this->installEntitySchema('plan_record');
    $this->installEntitySchema('file');
    $this->installConfig([
      'farm_activity',
      'farm_animal',
      'farm_animal_type',
      'farm_grazing_plan',
      'farm_land',
      'farm_observation',
      'farm_quantity_standard',
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
    $planned_start = (int) $grazing_event->get('start')->value;
    $this->assertEquals('pending', $grazing_event->getLog()->get('status')->value);
    $actual_timestamp = strtotime('2024-06-15 10:30:00');
    $this->submitQuickForm([
      'grazing_event' => $first_event_id,
      'date' => DrupalDateTime::createFromTimestamp($actual_timestamp),
      'notes' => [
        'value' => 'Completed in the field.',
        'format' => 'default',
      ],
      'observations' => [
        'next' => [
          'quantities' => [
            [
              'label' => 'Graze height',
              'value' => '12',
              'units' => 'cm',
              'measure' => 'length',
            ],
          ],
          'notes' => [
            'value' => 'Pre-graze height recorded.',
            'format' => 'default',
          ],
        ],
      ],
    ]);
    $log = \Drupal::entityTypeManager()->getStorage('log')->load($log_id);
    $this->assertEquals('done', $log->get('status')->value);
    $this->assertEquals($actual_timestamp, (int) $log->get('timestamp')->value);
    $this->assertStringContainsString('Completed in the field.', $log->get('notes')->value);

    // Planned start on the grazing event plan record is unchanged.
    $grazing_event = \Drupal::entityTypeManager()->getStorage('plan_record')->load($first_event_id);
    $this->assertEquals($planned_start, (int) $grazing_event->get('start')->value);

    // Confirm a next-paddock observation log was created with the quantity.
    $observation_logs = \Drupal::entityTypeManager()->getStorage('log')->loadByProperties([
      'type' => 'observation',
    ]);
    $this->assertNotEmpty($observation_logs);
    $observation = reset($observation_logs);
    $this->assertStringContainsString('Next paddock observation', $observation->label());
    $this->assertCount(1, $observation->get('quantity')->referencedEntities());
    $this->assertEquals('12', $observation->get('quantity')->referencedEntities()[0]->get('value')[0]->get('decimal')->getValue());

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
