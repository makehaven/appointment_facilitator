<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Verifies caching behavior for appointment stats summaries.
 *
 * @group appointment_facilitator
 */
class AppointmentStatsCacheTest extends KernelTestBase {

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

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'appointment_facilitator']);

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }
  }

  /**
   * Tests summarize() returns cached data when cache entry is present.
   */
  public function testSummarizeUsesCache(): void {
    $user = User::create([
      'name' => 'facilitator_cache_test',
      'mail' => 'facilitator-cache@example.com',
      'status' => 1,
    ]);
    $user->save();

    $node = Node::create([
      'type' => 'appointment',
      'title' => 'Cache Test Appointment',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $node->save();

    $options = [
      'include_cancelled' => TRUE,
      'use_facilitator_terms' => FALSE,
    ];

    $stats = $this->container->get('appointment_facilitator.stats');
    $summary = $stats->summarize(NULL, NULL, $options);
    $this->assertSame(1, $summary['total_appointments']);

    $method = new \ReflectionMethod($stats, 'buildSummaryCacheId');
    $method->setAccessible(TRUE);
    $cid = $method->invoke($stats, NULL, NULL, $options);

    $cache = $this->container->get('cache.default');
    $cached = $cache->get($cid);
    $this->assertNotFalse($cached);
    $this->assertIsArray($cached->data);

    $sentinel = ['_cached' => TRUE];
    $cache->set($cid, $sentinel, \Drupal::time()->getRequestTime() + 300);

    $from_cache = $stats->summarize(NULL, NULL, $options);
    $this->assertSame($sentinel, $from_cache);
  }

}
