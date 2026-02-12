<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Verifies appointment date normalization uses site timezone.
 *
 * @group appointment_facilitator
 */
class AppointmentFacilitatorNormalizeDateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'options',
    'taxonomy',
    'views',
    'comment',
    'datetime',
    'smart_date',
    'appointment_facilitator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * Ensures normalization honors configured site timezone.
   */
  public function testNormalizeDateUsesSiteTimezone(): void {
    \Drupal::configFactory()
      ->getEditable('system.date')
      ->set('timezone.default', 'America/New_York')
      ->save();

    $this->assertSame('2026-02-28', _appointment_facilitator_normalize_date('2026-03-01T00:30:00Z'));
    $this->assertSame('2026-03-01', _appointment_facilitator_normalize_date('2026-03-01'));
  }

}

