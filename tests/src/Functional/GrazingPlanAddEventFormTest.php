<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Functional;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_test\Functional\FarmBrowserTestBase;
use Drupal\log\Entity\Log;
use Drupal\plan\Entity\Plan;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the grazing plan add event form.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingPlanAddEventFormTest extends FarmBrowserTestBase {

  use MockGrazingPlanEntitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_grazing_plan',
    'farm_land',
  ];

  /**
   * Test adding a grazing event via the form.
   */
  public function testAddGrazingEvent() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Get log and plan_record entity storage.
    $log_storage = \Drupal::entityTypeManager()->getStorage('log');
    $plan_record_storage = \Drupal::entityTypeManager()->getStorage('plan_record');

    // Count log and plan_record entities.
    $expected_log_count = count($log_storage->loadMultiple());
    $expected_plan_record_count = count($plan_record_storage->loadMultiple());

    // Attempt to load the add grazing event form and confirm that access is
    // denied.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->assertSession()->statusCodeEquals(403);

    // Create and log in a user with the necessary permissions.
    $user = $this->drupalCreateUser([
      'view any grazing plan',
      'update any grazing plan',
      'view any activity log',
      'view any animal asset',
      'view any land asset',
      'access asset collection',
    ]);
    $this->drupalLogin($user);

    // Load the add grazing event form and confirm the user has access and all
    // expected fields are visible.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('existing_log');
    $this->assertSession()->fieldExists('log');
    $this->assertSession()->fieldExists('asset');
    $this->assertSession()->fieldExists('location');
    $this->assertSession()->fieldExists('start[date]');
    $this->assertSession()->fieldExists('start[time]');
    $this->assertSession()->fieldExists('duration');
    $this->assertSession()->fieldExists('recovery');

    // Confirm that a new event cannot be added with a start before the last
    // grazing event for the selected asset.
    $early_timestamp = reset($this->grazingEvents)->get('start')->value + 60;
    $asset = reset($this->animalAssets);
    $location = reset($this->landAssets);
    $this->submitForm([
      'asset' => $asset->label() . ' (' . $asset->id() . ')',
      'location' => $location->label() . ' (' . $location->id() . ')',
      'start[date]' => date('Y-m-d', $early_timestamp),
      'start[time]' => date('H:i:s', $early_timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');
    $this->assertSession()->pageTextContains('The planned start date/time is before the last existing grazing event for this asset in the plan. Grazing events can only be added to the end of the plan.');
    $this->assertCount($expected_log_count, $log_storage->loadMultiple());
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Confirm that an existing movement log with a timestamp before the last
    // grazing event for the asset cannot be added to the plan.
    $this->createMockGrazingEvent($early_timestamp, reset($this->animalAssets), reset($this->landAssets), FALSE);
    $early_log = end($this->movementLogs);
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'existing_log' => TRUE,
      'log' => $early_log->label() . ' (' . $early_log->id() . ')',
      'start[date]' => date('Y-m-d', $this->nextGrazingEventTimestamp()),
      'start[time]' => date('H:i:s', $this->nextGrazingEventTimestamp()),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');
    $this->assertSession()->pageTextContains('The movement log timestamp is before the last existing grazing event for this asset in the plan. Grazing events can only be added to the end of the plan.');
    $expected_log_count++;
    $this->assertCount($expected_log_count, $log_storage->loadMultiple());
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Reload the form.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');

    // Get a timestamp for the next grazing event.
    $timestamp = $this->nextGrazingEventTimestamp();

    // Select the first asset and location and submit the form.
    $asset = reset($this->animalAssets);
    $location = reset($this->landAssets);
    $this->submitForm([
      'asset' => $asset->label() . ' (' . $asset->id() . ')',
      'location' => $location->label() . ' (' . $location->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');

    // Confirm that the status messages are shown.
    $this->assertSession()->pageTextContains('Created log: Move ' . $asset->label() . ' to ' . $location->label());
    $this->assertSession()->pageTextContains('Added ' . date('Y-m-d', $timestamp) . ': ' . $asset->label() . ' -> ' . $location->label() . ' (' . $this->plan->label() . ')');

    // Confirm that a new log was created with the expected values.
    $expected_log_count++;
    $logs = $log_storage->loadMultiple();
    $this->assertCount($expected_log_count, $logs);
    $log = end($logs);
    $this->assertEquals('activity', $log->bundle());
    $this->assertEquals('Move ' . $asset->label() . ' to ' . $location->label(), $log->label());
    $this->assertEquals($timestamp, $log->get('timestamp')->value);
    $this->assertEquals($asset->id(), $log->get('asset')->target_id);
    $this->assertEquals($location->id(), $log->get('location')->target_id);
    $this->assertTrue((bool) $log->get('is_movement')->value);
    $this->assertEquals('done', $log->get('status')->value);

    // Confirm that a new grazing event plan record was created with the
    // expected values.
    $expected_plan_record_count++;
    $plan_records = $plan_record_storage->loadMultiple();
    $this->assertCount($expected_plan_record_count, $plan_records);
    $plan_record = end($plan_records);
    $this->assertEquals($this->plan->id(), $plan_record->get('plan')->target_id);
    $this->assertEquals($log->id(), $plan_record->get('log')->target_id);
    $this->assertEquals($timestamp, $plan_record->get('start')->value);
    $this->assertEquals(7 * 24, $plan_record->get('duration')->value);
    $this->assertEquals(15 * 24, $plan_record->get('recovery')->value);

    // Reload the form.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');

    // Select the first asset and location and submit the form with a timestamp
    // long after the last grazing event, to confirm that the log is pending.
    $asset = reset($this->animalAssets);
    $location = reset($this->landAssets);
    $first_event = end($this->grazingEvents);
    $timestamp = (int) $first_event->get('start')->value + (365 * 24 * 60 * 60);
    $this->submitForm([
      'asset' => $asset->label() . ' (' . $asset->id() . ')',
      'location' => $location->label() . ' (' . $location->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');

    // Confirm that the status messages are shown.
    $this->assertSession()->pageTextContains('Created log: Move ' . $asset->label() . ' to ' . $location->label());
    $this->assertSession()->pageTextContains('Added ' . date('Y-m-d', $timestamp) . ': ' . $asset->label() . ' -> ' . $location->label() . ' (' . $this->plan->label() . ')');

    // Confirm that a new log was created with the expected values.
    $expected_log_count++;
    $logs = $log_storage->loadMultiple();
    $this->assertCount($expected_log_count, $logs);
    $log = end($logs);
    $this->assertEquals('activity', $log->bundle());
    $this->assertEquals('Move ' . $asset->label() . ' to ' . $location->label(), $log->label());
    $this->assertEquals($timestamp, $log->get('timestamp')->value);
    $this->assertEquals($asset->id(), $log->get('asset')->target_id);
    $this->assertEquals($location->id(), $log->get('location')->target_id);
    $this->assertTrue((bool) $log->get('is_movement')->value);
    $this->assertEquals('pending', $log->get('status')->value);

    // Confirm that a new grazing event plan record was created with the
    // expected values.
    $expected_plan_record_count++;
    $plan_records = $plan_record_storage->loadMultiple();
    $this->assertCount($expected_plan_record_count, $plan_records);
    $plan_record = end($plan_records);
    $this->assertEquals($this->plan->id(), $plan_record->get('plan')->target_id);
    $this->assertEquals($log->id(), $plan_record->get('log')->target_id);
    $this->assertEquals($timestamp, $plan_record->get('start')->value);
    $this->assertEquals(7 * 24, $plan_record->get('duration')->value);
    $this->assertEquals(15 * 24, $plan_record->get('recovery')->value);

    // Track the new grazing event for calculating future timestamps.
    $this->grazingEvents[] = $plan_record;

    // Reload the form.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');

    // Get a timestamp for the next grazing event.
    $timestamp = $this->nextGrazingEventTimestamp();

    // Create a movement log that is not yet linked to the plan.
    $this->createMockGrazingEvent($timestamp, $this->animalAssets[0], $this->landAssets[0], FALSE);
    $log = end($this->movementLogs);

    // Submit the form.
    $edit = [
      'existing_log' => TRUE,
      'log' => $log->label() . ' (' . $log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ];
    $this->submitForm($edit, 'Save');

    // Confirm that the status message is shown.
    $this->assertSession()->pageTextContains('Added ' . date('Y-m-d', $timestamp) . ': ' . $this->animalAssets[0]->label() . ' -> ' . $this->landAssets[0]->label() . ' (' . $this->plan->label() . ')');

    // Confirm the grazing event plan record was created with the expected
    // values.
    $expected_plan_record_count++;
    $plan_records = $plan_record_storage->loadMultiple();
    $this->assertCount($expected_plan_record_count, $plan_records);
    $plan_record = end($plan_records);
    $this->assertEquals($this->plan->id(), $plan_record->get('plan')->target_id);
    $this->assertEquals($log->id(), $plan_record->get('log')->target_id);
    $this->assertEquals($timestamp, $plan_record->get('start')->value);
    $this->assertEquals(7 * 24, $plan_record->get('duration')->value);
    $this->assertEquals(15 * 24, $plan_record->get('recovery')->value);

    // Track the new grazing event for calculating future timestamps.
    $this->grazingEvents[] = $plan_record;

    // Submit the same log a second time and confirm the duplicate check works.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('This log is already part of a grazing plan.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a second plan and confirm that the log cannot be added to that
    // plan either.
    $plan2 = Plan::create([
      'name' => $this->randomMachineName(),
      'type' => 'grazing',
    ]);
    $plan2->save();
    $this->drupalGet('/plan/' . $plan2->id() . '/grazing/event');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('This log is already part of a grazing plan.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Get a timestamp for the next grazing event.
    $timestamp = $this->nextGrazingEventTimestamp();

    // Create a non-movement log and confirm that it cannot be added.
    $non_movement_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => $timestamp,
      'asset' => [
        ['target_id' => $this->animalAssets[0]->id()],
      ],
      'location' => [
        ['target_id' => $this->landAssets[0]->id()],
      ],
      'status' => 'done',
      'is_movement' => FALSE,
    ]);
    $non_movement_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'existing_log' => TRUE,
      'log' => $non_movement_log->label() . ' (' . $non_movement_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');
    $this->assertSession()->pageTextContains('Only movement logs can be added to a grazing plan.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a log without an asset and confirm that it cannot be added.
    $no_asset_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'location' => [
        ['target_id' => $this->landAssets[0]->id()],
      ],
      'status' => 'done',
      'is_movement' => TRUE,
    ]);
    $no_asset_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'existing_log' => TRUE,
      'log' => $no_asset_log->label() . ' (' . $no_asset_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');
    $this->assertSession()->pageTextContains('This log does not reference an asset. A grazing event must move one asset.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a log without a location and confirm that it cannot be added.
    $no_location_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'asset' => [
        ['target_id' => $this->animalAssets[0]->id()],
      ],
      'status' => 'done',
      'is_movement' => TRUE,
    ]);
    $no_location_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'existing_log' => TRUE,
      'log' => $no_location_log->label() . ' (' . $no_location_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');
    $this->assertSession()->pageTextContains('This log does not reference a location. A grazing event must move an asset to a location.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a multi-asset log and confirm that it cannot be added.
    $multi_asset_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'asset' => [
        ['target_id' => $this->animalAssets[0]->id()],
        ['target_id' => $this->animalAssets[1]->id()],
      ],
      'location' => [
        ['target_id' => $this->landAssets[0]->id()],
      ],
      'status' => 'done',
      'is_movement' => TRUE,
    ]);
    $multi_asset_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'existing_log' => TRUE,
      'log' => $multi_asset_log->label() . ' (' . $multi_asset_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');
    $this->assertSession()->pageTextContains('This log references multiple assets. A grazing event can only move one asset.');
    $this->assertSession()->pageTextContains('Tip: The Group asset type can be used');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a multi-location log and confirm that it cannot be added.
    $multi_location_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'asset' => [
        ['target_id' => $this->animalAssets[0]->id()],
      ],
      'location' => [
        ['target_id' => $this->landAssets[0]->id()],
        ['target_id' => $this->landAssets[1]->id()],
      ],
      'status' => 'done',
      'is_movement' => TRUE,
    ]);
    $multi_location_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'existing_log' => TRUE,
      'log' => $multi_location_log->label() . ' (' . $multi_location_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');
    $this->assertSession()->pageTextContains('This log references multiple locations. A grazing event can only move an asset to a single location.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Reload the form.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');

    // Get a timestamp for the next grazing event.
    $timestamp = $this->nextGrazingEventTimestamp();

    // Create a movement log that is not yet linked to the plan.
    $this->createMockGrazingEvent($timestamp, $this->animalAssets[0], $this->landAssets[0], FALSE);
    $log = end($this->movementLogs);

    // Load the add grazing event form with a log ID query parameter and
    // confirm the existing movement log fields are pre-populated.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event', ['query' => ['log' => $log->id()]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->checkboxChecked('existing_log');
    $this->assertSession()->fieldValueEquals('log', $log->label() . ' (' . $log->id() . ')');

    // Submit the form, using the pre-populated existing movement log values.
    $this->submitForm([
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7,
      'recovery' => 15,
    ], 'Save');

    // Confirm that the status message is shown.
    $this->assertSession()->pageTextContains('Added ' . date('Y-m-d', $timestamp) . ': ' . $this->animalAssets[0]->label() . ' -> ' . $this->landAssets[0]->label() . ' (' . $this->plan->label() . ')');

    // Confirm the grazing event plan record was created with the expected
    // values.
    $expected_plan_record_count++;
    $plan_records = $plan_record_storage->loadMultiple();
    $this->assertCount($expected_plan_record_count, $plan_records);
    $plan_record = end($plan_records);
    $this->assertEquals($this->plan->id(), $plan_record->get('plan')->target_id);
    $this->assertEquals($log->id(), $plan_record->get('log')->target_id);
  }

}
