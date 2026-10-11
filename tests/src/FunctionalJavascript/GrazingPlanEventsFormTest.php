<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\FunctionalJavascript;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_test\FunctionalJavascript\FarmWebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the grazing plan form.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingPlanEventsFormTest extends FarmWebDriverTestBase {

  use MockGrazingPlanEntitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_grazing_plan',
    'farm_land',
  ];

  /**
   * Test the grazing plan form.
   */
  public function testGrazingPlanEventsForm() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Attempt to load the grazing plan form and confirm that access is denied.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->assertSession()->pageTextContains('You are not authorized to access this page.');
    $this->assertSession()->pageTextNotContains('Grazing events (by Asset)');

    // Create and log in a user with access to view grazing plans.
    $permissions = ['view any grazing plan'];
    $user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($user);

    // Load the grazing plan and confirm the user has access.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->assertSession()->pageTextNotContains('You are not authorized to access this page.');
    $this->assertSession()->pageTextContains('Grazing events (by Asset)');

    // Confirm that the grazing plan form is inaccessible.
    $this->assertSession()->buttonNotExists('Save events');

    // Create and log in a user with access to update grazing plans.
    $permissions[] = 'update any grazing plan';
    // The form checks access('view') on each asset, so the user must also be
    // able to view the animal assets.
    $permissions[] = 'view any animal asset';
    // Also grant permissions necessary for the location entity reference
    // fields.
    $permissions[] = 'access asset collection';
    $permissions[] = 'view any land asset';
    $user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($user);

    // Load the grazing plan and confirm the user has access to the form.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->assertSession()->buttonExists('Save events');

    // Confirm that the "Complete" button is not shown, because the user does
    // not have access to the "Complete grazing event" quick form.
    $this->assertCount(0, $this->getSession()->getPage()->findAll('css', '#farm-grazing-plan-events-form input[name^="complete_grazing_event_"]'));

    // Grant access to the "Complete grazing event" quick form.
    $permissions[] = 'update any activity log';
    $user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($user);
    $this->drupalGet('/plan/' . $this->plan->id());

    // Get the plan's grazing events grouped by asset.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);

    // Confirm that the "Complete" button is shown on the first pending event of
    // each asset that has pending events.
    $expected_complete_links = 0;
    foreach ($grazing_events_by_asset as $grazing_events) {
      foreach ($grazing_events as $grazing_event) {
        if ($grazing_event->getStatus() === 'pending') {
          $expected_complete_links++;
          break;
        }
      }
    }
    $this->assertCount($expected_complete_links, $this->getSession()->getPage()->findAll('css', '#farm-grazing-plan-events-form input[name^="complete_grazing_event_"]'));

    // Confirm the expected fields are present for each asset and each of its
    // grazing events.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {
      foreach ($grazing_events as $grazing_event_id => $grazing_event) {
        $status = $grazing_event->getLog()->get('status')->value;
        if ($status != 'pending') {
          continue;
        }
        $prefix = 'grazing_events[' . $asset_id . '][' . $status . '][' . $grazing_event_id . ']';
        $this->assertSession()->fieldExists($prefix . '[planned_duration]');
        $this->assertSession()->fieldExists($prefix . '[planned_recovery]');
      }
    }

    // Confirm at least one submit button exists.
    $this->assertSession()->buttonExists('Save events');

    // Record the original values so that we can compare them later.
    $original = [];
    foreach ($grazing_events_by_asset as $asset_events) {
      foreach ($asset_events as $grazing_event) {
        $original[$grazing_event->id()] = [
          'start' => $grazing_event->get('start')->value,
          'duration' => $grazing_event->get('duration')->value,
          'recovery' => $grazing_event->get('recovery')->value,
          'timestamp' => $grazing_event->getLog()->get('timestamp')->value,
          'location' => $grazing_event->getLog()->get('location')->target_id,
        ];
      }
    }

    // Double the planned duration and recovery times. The next planned movement
    // dates are left at their default values, so the pending starts are
    // recomputed on submit. The locations are also not editable, and are not
    // updated on submit.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {

      // Click the vertical tab.
      $this->clickGrazingEventsTab($asset_id);

      // Iterate through the grazing event rows.
      foreach ($grazing_events as $grazing_event) {
        $status = $grazing_event->getLog()->get('status')->value;
        if ($status != 'pending') {
          continue;
        }
        $prefix = 'grazing_events[' . $asset_id . '][' . $status . '][' . $grazing_event->id() . ']';

        // Double the planned duration and recovery times. The form fields
        // use days, so convert the entity values (in hours) to days.
        $this->getSession()->getPage()->fillField($prefix . '[planned_duration]', (string) ($grazing_event->get('duration')->value / 24 * 2));
        $this->getSession()->getPage()->fillField($prefix . '[planned_recovery]', (string) ($grazing_event->get('recovery')->value / 24 * 2));
      }
    }

    // Click on the first asset's vertical tab and press the submit button.
    $this->clickGrazingEventsTab(array_key_first($grazing_events_by_asset));
    $this->getSession()->getPage()->pressButton('Save events');

    // Confirm that the status message is shown.
    $this->assertTrue($this->assertSession()->waitForText('Updated the grazing events.', 30000));

    // Reload the page to ensure entities are refreshed.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Confirm the grazing events and logs are updated with the expected
    // values.
    $plan_record_storage = \Drupal::entityTypeManager()->getStorage('plan_record');
    $log_storage = \Drupal::entityTypeManager()->getStorage('log');
    foreach ($grazing_events_by_asset as $grazing_events) {
      foreach ($grazing_events as $grazing_event) {
        $status = $grazing_event->getLog()->get('status')->value;
        if ($status != 'pending') {
          continue;
        }

        // The pending start dates are recomputed on submit, anchored to the
        // next planned movement date, which defaults to the first pending
        // event's start date. For this test data, that matches the original
        // start date. The duration and recovery are doubled.
        $updated_grazing_event = $plan_record_storage->load($grazing_event->id());
        $this->assertEquals($original[$grazing_event->id()]['start'], $updated_grazing_event->get('start')->value);
        $this->assertEquals($original[$grazing_event->id()]['duration'] * 2, $updated_grazing_event->get('duration')->value);
        $this->assertEquals($original[$grazing_event->id()]['recovery'] * 2, $updated_grazing_event->get('recovery')->value);

        // The log should have the original timestamp and the original location,
        // which is not updated since the location is not editable.
        $log = $log_storage->load($grazing_event->get('log')->target_id);
        $this->assertEquals($original[$grazing_event->id()]['timestamp'], $log->get('timestamp')->value);
        $this->assertEquals($original[$grazing_event->id()]['location'], $log->get('location')->target_id);
      }
    }

    // Reload the page to test the "Add event" button.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Reload the grazing events, now that they have been updated.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);

    // Get the asset in the first vertical tab and its most recent grazing
    // event.
    $first_asset_id = array_key_first($grazing_events_by_asset);
    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($first_asset_id);
    $grazing_events = array_values($grazing_events_by_asset[$first_asset_id]);
    $last_grazing_event = end($grazing_events);

    // The new row should be pre-filled based on the most recent grazing event:
    // the pending start defaults to the most recent log timestamp plus the
    // duration, and the duration and recovery default to the most recent
    // grazing event's values, converted from hours to days.
    $expected_start = $last_grazing_event->getLog()->get('timestamp')->value + $last_grazing_event->get('duration')->value * 60 * 60;
    $expected_duration = $last_grazing_event->get('duration')->value;
    $expected_recovery = $last_grazing_event->get('recovery')->value;

    // Open the first asset's vertical tab. The "Add event" button is found by
    // name, since there is one per asset.
    $this->clickGrazingEventsTab($first_asset_id);
    $button = $this->getSession()->getPage()->find('xpath', "//input[@name='add_grazing_event_{$first_asset_id}']");
    $this->assertNotNull($button);
    $button->press();

    // Wait for Ajax.
    $prefix = 'grazing_events[' . $first_asset_id . '][pending][new_1]';
    $this->assertNotNull($this->assertSession()->waitForField($prefix . '[location]', 30000));

    // Confirm the new row is rendered with the expected pre-filled values.
    // The duration and recovery fields are in days, so convert the expected
    // values (in hours) to days.
    $this->assertSession()->fieldValueEquals($prefix . '[location]', '');
    $this->assertSession()->fieldValueEquals($prefix . '[planned_duration]', (string) ($expected_duration / 24));
    $this->assertSession()->fieldValueEquals($prefix . '[planned_recovery]', (string) ($expected_recovery / 24));

    // Confirm the pending event is still present in the re-rendered pending
    // table, and that only one new row was added.
    $this->assertSession()->fieldExists('grazing_events[' . $first_asset_id . '][pending][' . $last_grazing_event->id() . '][planned_duration]');
    $this->assertSession()->fieldNotExists('grazing_events[' . $first_asset_id . '][pending][new_2][location]');

    // Set the location of the new grazing event.
    $location = $this->landAssets[0];
    $this->getSession()->getPage()->fillField($prefix . '[location]', $location->label() . ' (' . $location->id() . ')');

    // Count log and plan_record entities.
    $expected_log_count = count($log_storage->loadMultiple());
    $expected_plan_record_count = count($plan_record_storage->loadMultiple());

    // Submit the form.
    $this->getSession()->getPage()->pressButton('Save events');
    $this->assertTrue($this->assertSession()->waitForText('Updated the grazing events.', 30000));

    // Confirm that a new movement log was created with the expected values.
    $expected_log_count++;
    $logs = $log_storage->loadMultiple();
    $this->assertCount($expected_log_count, $logs);
    $log_ids = $log_storage->getQuery()
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    $log = $log_storage->load(reset($log_ids));
    $this->assertEquals('activity', $log->bundle());
    $this->assertEquals('Move ' . $asset->label() . ' to ' . $location->label(), $log->label());
    $this->assertEquals($expected_start, $log->get('timestamp')->value);
    $this->assertEquals($first_asset_id, $log->get('asset')->target_id);
    $this->assertEquals($location->id(), $log->get('location')->target_id);
    $this->assertTrue((bool) $log->get('is_movement')->value);
    $this->assertEquals('pending', $log->get('status')->value);

    // Confirm that a new grazing event plan record was created with the
    // expected values.
    $expected_plan_record_count++;
    $plan_records = $plan_record_storage->loadMultiple();
    $this->assertCount($expected_plan_record_count, $plan_records);
    $plan_record_ids = $plan_record_storage->getQuery()
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    $plan_record = $plan_record_storage->load(reset($plan_record_ids));
    $this->assertEquals('grazing_event', $plan_record->bundle());
    $this->assertEquals($this->plan->id(), $plan_record->get('plan')->target_id);
    $this->assertEquals($log->id(), $plan_record->get('log')->target_id);
    $this->assertEquals($expected_start, $plan_record->get('start')->value);
    $this->assertEquals($expected_duration, $plan_record->get('duration')->value);
    $this->assertEquals($expected_recovery, $plan_record->get('recovery')->value);

    // Reload the page to test reordering the pending grazing events.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Get the pending and done grazing events for the first asset. The first
    // asset should now have two pending grazing events: the original one and
    // the one added above.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);
    $grazing_events = $grazing_events_by_asset[$first_asset_id];
    $pending_events = array_filter($grazing_events, fn ($event) => $event->getLog()->get('status')->value !== 'done');
    $this->assertCount(2, $pending_events);
    $first_pending = reset($pending_events);
    $last_pending = end($pending_events);
    $done_events = array_filter($grazing_events, fn ($event) => $event->getLog()->get('status')->value === 'done');
    $last_done = end($done_events);

    // The next planned movement date defaults to the first pending event's
    // start date, which for this test data equals the end of the last done
    // grazing event.
    $anchor_start = $last_done->getLog()->get('timestamp')->value + $last_done->get('duration')->value * 60 * 60;

    // Open the first asset's vertical tab.
    $this->clickGrazingEventsTab($first_asset_id);

    // Confirm that the "Complete" button appears on the first pending event's
    // row, and not on the second.
    $complete_button = 'input[name="complete_grazing_event_' . $first_asset_id . '"]';
    $first_row = $this->findGrazingEventRow($first_asset_id, $first_pending->id());
    $this->assertNotNull($first_row->find('css', $complete_button));
    $second_row = $this->findGrazingEventRow($first_asset_id, $last_pending->id());
    $this->assertNull($second_row->find('css', $complete_button));

    // Drag the last pending row onto the first pending row, so the pending
    // grazing events are reordered.
    $rows = $this->getSession()->getPage()->findAll('css', '#edit-grazing-events-' . $first_asset_id . '-pending tr.draggable');
    $this->assertCount(2, $rows);
    $source_handle = $rows[1]->find('css', 'a.tabledrag-handle');
    $target_handle = $rows[0]->find('css', 'a.tabledrag-handle');
    $this->assertNotNull($source_handle);
    $this->assertNotNull($target_handle);
    $source_handle->dragTo($target_handle);

    // Confirm that the weights were updated by the drag, so that the dragged
    // row now comes first. For weight select fields, the tabledrag JavaScript
    // assigns the select's option values in order, so only the relative
    // order of the weights is asserted.
    $weight_last_pending = $this->getSession()->getPage()->findField('grazing_events[' . $first_asset_id . '][pending][' . $last_pending->id() . '][weight]')->getValue();
    $weight_first_pending = $this->getSession()->getPage()->findField('grazing_events[' . $first_asset_id . '][pending][' . $first_pending->id() . '][weight]')->getValue();
    $this->assertLessThan($weight_first_pending, $weight_last_pending);

    // Save the events.
    $this->getSession()->getPage()->pressButton('Save events');
    $this->assertTrue($this->assertSession()->waitForText('Updated the grazing events.', 30000));

    // Confirm that the start dates of the pending grazing events and their
    // logs were recomputed based on the new order. The first pending event
    // starts at the anchor, and the second pending event starts at the end of
    // the first pending event.
    $this->drupalGet('/plan/' . $this->plan->id());
    $updated_last_pending = $plan_record_storage->load($last_pending->id());
    $this->assertEquals($anchor_start, $updated_last_pending->get('start')->value);
    $log = $log_storage->load($updated_last_pending->get('log')->target_id);
    $this->assertEquals($anchor_start, $log->get('timestamp')->value);
    $updated_first_pending = $plan_record_storage->load($first_pending->id());
    $this->assertEquals($anchor_start + $last_pending->get('duration')->value * 60 * 60, $updated_first_pending->get('start')->value);

    // Reload the page to test the "Next planned movement" date field.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Get the pending grazing events for the first asset, in the new order.
    $grazing_events = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan)[$first_asset_id];
    $pending_events = array_values(array_filter($grazing_events, fn ($event) => $event->getLog()->get('status')->value !== 'done'));
    $this->assertCount(2, $pending_events);
    $first_pending = $pending_events[0];
    $second_pending = $pending_events[1];

    // Open the first asset's vertical tab and the "Next planned movement"
    // details box, which is collapsed by default.
    $this->clickGrazingEventsTab($first_asset_id);
    $this->openNextPlannedMovementDetails($first_asset_id);

    // Set the next planned movement date to 10 days after the first pending
    // event's current start date.
    $new_date = date('Y-m-d', $first_pending->get('start')->value + 10 * 24 * 60 * 60);
    $this->fillDateField('grazing_events[' . $first_asset_id . '][pending_start][date]', $new_date);

    // Save the events.
    $this->getSession()->getPage()->pressButton('Save events');
    $this->assertTrue($this->assertSession()->waitForText('Updated the grazing events.', 30000));

    // Confirm that the pending start dates and their logs were recomputed
    // from the new next planned movement date, which is stored as a timestamp
    // at midnight. The first pending event starts on the new date, and the
    // second pending event starts at the end of the first pending event.
    $this->drupalGet('/plan/' . $this->plan->id());
    $expected_start = strtotime($new_date);
    $updated_first_pending = $plan_record_storage->load($first_pending->id());
    $this->assertEquals($expected_start, $updated_first_pending->get('start')->value);
    $log = $log_storage->load($updated_first_pending->get('log')->target_id);
    $this->assertEquals($expected_start, $log->get('timestamp')->value);
    $updated_second_pending = $plan_record_storage->load($second_pending->id());
    $this->assertEquals($expected_start + $first_pending->get('duration')->value * 60 * 60, $updated_second_pending->get('start')->value);

    // Confirm that the next planned movement date field defaults to the first
    // pending event's start date.
    $this->clickGrazingEventsTab($first_asset_id);
    $this->assertSession()->fieldValueEquals('grazing_events[' . $first_asset_id . '][pending_start][date]', $new_date);

    // Test that a next planned movement date before the last completed event's
    // start date is rejected, and that the pending events are not changed.
    $this->clickGrazingEventsTab($first_asset_id);
    $this->openNextPlannedMovementDetails($first_asset_id);
    $invalid_date = date('Y-m-d', $last_done->getLog()->get('timestamp')->value - 24 * 60 * 60);
    $this->fillDateField('grazing_events[' . $first_asset_id . '][pending_start][date]', $invalid_date);
    $this->getSession()->getPage()->pressButton('Save events');
    $this->assertSession()->waitForText('cannot be before the start date of its last completed event', 30000);
    $this->drupalGet('/plan/' . $this->plan->id());
    $updated_first_pending = $plan_record_storage->load($first_pending->id());
    $this->assertEquals($expected_start, $updated_first_pending->get('start')->value);

    // Add a new row via Ajax and then test that the "Remove" button works.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->clickGrazingEventsTab($first_asset_id);
    $button = $this->getSession()->getPage()->find('xpath', "//input[@name='add_grazing_event_{$first_asset_id}']");
    $this->assertNotNull($button);
    $button->press();
    $prefix = 'grazing_events[' . $first_asset_id . '][pending][new_1]';
    $this->assertNotNull($this->assertSession()->waitForField($prefix . '[location]', 30000));
    $remove_button = $this->getSession()->getPage()->find('xpath', "//input[@name='remove_grazing_event_{$first_asset_id}_1']");
    $this->assertNotNull($remove_button);
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'input[name^="remove_grazing_event_"]'));
    $expected_log_count = count($log_storage->loadMultiple());
    $expected_plan_record_count = count($plan_record_storage->loadMultiple());
    $remove_button->press();
    $this->assertTrue($this->waitForFieldRemoved($prefix . '[location]', 30000));
    $this->assertCount($expected_log_count, $log_storage->loadMultiple());
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Add a new row again via Ajax. The removed row key is not reused, so
    // the new row should be "new_2".
    $button = $this->getSession()->getPage()->find('xpath', "//input[@name='add_grazing_event_{$first_asset_id}']");
    $this->assertNotNull($button);
    $button->press();
    $prefix2 = 'grazing_events[' . $first_asset_id . '][pending][new_2]';
    $this->assertNotNull($this->assertSession()->waitForField($prefix2 . '[location]', 30000));
    $this->assertSession()->fieldNotExists($prefix . '[location]');

    // Submit the form and confirm that exactly one new movement log and
    // grazing event were created, from the remaining new row, and that the
    // removed row was not created.
    $location = $this->landAssets[0];
    $this->getSession()->getPage()->fillField($prefix2 . '[location]', $location->label() . ' (' . $location->id() . ')');
    $this->getSession()->getPage()->fillField($prefix2 . '[planned_duration]', '5');
    $this->getSession()->getPage()->pressButton('Save events');
    $this->assertTrue($this->assertSession()->waitForText('Updated the grazing events.', 30000));
    $this->assertCount($expected_log_count + 1, $log_storage->loadMultiple());
    $this->assertCount($expected_plan_record_count + 1, $plan_record_storage->loadMultiple());
    $plan_record_ids = $plan_record_storage->getQuery()
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    $plan_record = $plan_record_storage->load(reset($plan_record_ids));
    $this->assertEquals(120, $plan_record->get('duration')->value);
    $log = $log_storage->load($plan_record->get('log')->target_id);
    $this->assertEquals('pending', $log->get('status')->value);
    $this->assertEquals($location->id(), $log->get('location')->target_id);

    // Test that the "Complete" button redirects to the "Complete grazing event"
    // quick form without saving the form.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->clickGrazingEventsTab($first_asset_id);
    $expected_log_count = count($log_storage->loadMultiple());
    $expected_plan_record_count = count($plan_record_storage->loadMultiple());
    $button = $this->getSession()->getPage()->find('css', '#farm-grazing-plan-events-form input[name^="complete_grazing_event_"]');
    $this->assertNotNull($button);
    $button->press();
    $this->assertTrue($this->assertSession()->waitForText('Select a pending grazing event.', 30000));
    $this->assertSession()->buttonExists('Submit');
    $this->assertCount($expected_log_count, $log_storage->loadMultiple());
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Test the "Abandon" button, which is shown on every pending event,
    // unlike the "Complete" button, which is only shown on the first.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Reload the grazing events, grouped by asset. The first asset now has
    // three pending events, and the second asset has one.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);
    $second_asset_id = array_key_last($grazing_events_by_asset);
    $expected_abandon_count = 0;
    foreach ($grazing_events_by_asset as $grazing_events) {
      foreach ($grazing_events as $grazing_event) {
        if ($grazing_event->getStatus() === 'pending') {
          $expected_abandon_count++;
        }
      }
    }
    $this->assertCount($expected_abandon_count, $this->getSession()->getPage()->findAll('css', '#farm-grazing-plan-events-form input[name^="abandon_grazing_event_"]'));

    // Confirm that each of the first asset's pending event rows has its own
    // "Abandon" button.
    $this->clickGrazingEventsTab($first_asset_id);
    $pending_events = array_filter($grazing_events_by_asset[$first_asset_id], fn ($event) => $event->getStatus() === 'pending');
    foreach ($pending_events as $grazing_event) {
      $row = $this->findGrazingEventRow($first_asset_id, $grazing_event->id());
      $this->assertNotNull($row->find('css', 'input[name="abandon_grazing_event_' . $grazing_event->id() . '"]'));
    }

    // Confirm that the "Abandon" button is not shown on new (unsaved) rows.
    $button = $this->getSession()->getPage()->find('xpath', "//input[@name='add_grazing_event_{$first_asset_id}']");
    $this->assertNotNull($button);
    $button->press();
    $new_row_prefix = 'grazing_events[' . $first_asset_id . '][pending][new_1]';
    $this->assertNotNull($this->assertSession()->waitForField($new_row_prefix . '[location]', 30000));
    $new_row = $this->findGrazingEventRow($first_asset_id, 'new_1');
    $this->assertNull($new_row->find('css', 'input[name^="abandon_grazing_event_"]'));
    $this->assertCount($expected_abandon_count, $this->getSession()->getPage()->findAll('css', '#farm-grazing-plan-events-form input[name^="abandon_grazing_event_"]'));

    // Test that cancelling the confirmation does not abandon the event.
    $cancel_event = reset($pending_events);
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->clickGrazingEventsTab($first_asset_id);
    $abandon_button = $this->getSession()->getPage()->find('css', '#farm-grazing-plan-events-form input[name="abandon_grazing_event_' . $cancel_event->id() . '"]');
    $this->assertNotNull($abandon_button);
    $abandon_button->press();
    $this->assertTrue($this->assertSession()->waitForText('This will mark the movement log of grazing event', 30000));
    $this->assertSession()->pageTextContains($cancel_event->label());
    $this->assertSession()->buttonExists('Confirm');
    $this->assertSession()->linkExists('Cancel');
    $this->clickLink('Cancel');
    $this->assertTrue($this->assertSession()->waitForText('Grazing events (by Asset)', 30000));
    $log = $log_storage->load($cancel_event->get('log')->target_id);
    $this->assertEquals('pending', $log->get('status')->value);

    // Test that confirming the abandonment abandons the second asset's
    // pending event.
    $pending_events = array_filter($grazing_events_by_asset[$second_asset_id], fn ($event) => $event->getStatus() === 'pending');
    $this->assertCount(1, $pending_events);
    $confirm_event = reset($pending_events);
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->clickGrazingEventsTab($second_asset_id);
    $expected_log_count = count($log_storage->loadMultiple());
    $expected_plan_record_count = count($plan_record_storage->loadMultiple());
    $abandon_button = $this->getSession()->getPage()->find('css', '#farm-grazing-plan-events-form input[name="abandon_grazing_event_' . $confirm_event->id() . '"]');
    $this->assertNotNull($abandon_button);
    $abandon_button->press();
    $this->assertTrue($this->assertSession()->waitForText('This will mark the movement log of grazing event', 30000));

    // Provide a reason and confirm the abandonment.
    $this->getSession()->getPage()->fillField('reason', 'No longer needed.');
    $this->getSession()->getPage()->pressButton('Confirm');
    $this->assertTrue($this->assertSession()->waitForText('Grazing events (by Asset)', 30000));

    // Confirm the log is marked as abandoned, with the reason included in the
    // revision message, and that no entities were deleted.
    // Reload the page first to ensure we load the updated log.
    $this->drupalGet('/plan/' . $this->plan->id());
    $log = $log_storage->load($confirm_event->get('log')->target_id);
    $this->assertEquals('abandoned', $log->get('status')->value);
    $this->assertStringContainsString('Grazing event abandoned', $log->getRevisionLogMessage());
    $this->assertStringContainsString('No longer needed.', $log->getRevisionLogMessage());
    $this->assertCount($expected_log_count, $log_storage->loadMultiple());
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Confirm the abandoned event no longer shows in the pending table, and
    // that the empty text is shown for the second asset.
    $this->assertSession()->fieldNotExists('grazing_events[' . $second_asset_id . '][pending][' . $confirm_event->id() . '][planned_duration]');
    $this->clickGrazingEventsTab($second_asset_id);
    $this->assertSession()->pageTextContains('No pending grazing events.');

    // Confirm the remaining "Abandon" buttons are shown.
    $this->assertCount($expected_abandon_count - 1, $this->getSession()->getPage()->findAll('css', '#farm-grazing-plan-events-form input[name^="abandon_grazing_event_"]'));
  }

  /**
   * Click the vertical tab for an asset's grazing events.
   *
   * The tab link is identified by its href, which is the ID of the asset's
   * details element.
   */
  protected function clickGrazingEventsTab(int $asset_id): void {
    $tab = $this->getSession()->getPage()->find('xpath', "//a[@href='#edit-grazing-events-{$asset_id}']");
    $this->assertNotNull($tab);
    $tab->click();
  }

  /**
   * Open the "Next planned movement" details box for an asset.
   *
   * The details box is collapsed by default, and the date field inside must
   * be visible to be filled by the test.
   */
  protected function openNextPlannedMovementDetails(int $asset_id): void {
    $summary = $this->getSession()->getPage()->find('xpath', "//details[@id='edit-grazing-events-{$asset_id}-pending-start']/summary");
    $this->assertNotNull($summary);
    $summary->click();
  }

  /**
   * Find the table row for a saved pending grazing event.
   *
   * The row is located via the planned duration field of the grazing event,
   * which is unique to the row. Then we traverse up to the row element.
   *
   * @param int $asset_id
   *   The asset ID.
   * @param int|string $grazing_event_id
   *   The grazing event (plan record) ID. Entity IDs loaded from storage are
   *   strings, because database drivers return numeric values as strings.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   Returns the table row element.
   */
  protected function findGrazingEventRow(int $asset_id, int|string $grazing_event_id) {
    $field = $this->getSession()->getPage()->findField('grazing_events[' . $asset_id . '][pending][' . $grazing_event_id . '][planned_duration]');
    $this->assertNotNull($field);
    return $field->find('xpath', 'ancestor::tr[1]');
  }

  /**
   * Fill a date or time input field by setting its value via JavaScript.
   *
   * HTML5 date and time inputs cannot be filled reliably by typing, so set
   * their value directly. Assert that the browser accepted the value.
   *
   * @param string $name
   *   The name attribute of the input field.
   * @param string $value
   *   The value to set, in ISO format (Y-m-d or H:i:s).
   */
  protected function fillDateField(string $name, string $value): void {

    // Note: The script must begin with "return", otherwise evaluateScript()
    // prepends it, causing a JavaScript syntax error.
    $actual = $this->getSession()->evaluateScript(
      'return (function() { var el = document.querySelector(\'input[name="' . $name . '"]\'); el.value = ' . json_encode($value) . '; return el.value; })();'
    );

    // Ensure the browser accepted the value.
    $this->assertSame($value, $actual);
  }

  /**
   * Wait for a field to be removed from the page.
   *
   * Polls the page until the field no longer exists, or the timeout is
   * reached. Used to wait for an Ajax request that removes an element.
   *
   * @param string $name
   *   The name attribute of the input field.
   * @param int $timeout
   *   The timeout, in milliseconds.
   *
   * @return bool
   *   Returns TRUE if the field was removed before the timeout, or FALSE
   *   otherwise.
   */
  protected function waitForFieldRemoved(string $name, int $timeout): bool {
    $start = microtime(TRUE);
    while ((microtime(TRUE) - $start) * 1000 < $timeout) {
      if (!$this->getSession()->getPage()->findField($name)) {
        return TRUE;
      }
      usleep(500000);
    }
    return FALSE;
  }

}
