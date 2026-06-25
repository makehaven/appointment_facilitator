<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the badge-checkout selection handler honors BOTH issuer fields.
 *
 * Regression: the old Views-based handler matched the chosen facilitator
 * (host-uid) against field_badge_issuer only, so an on-request-only issuer
 * produced an empty, unselectable badge list and an impossible "select a
 * badge" error. The handler must list — and validate — badges issuable via
 * either field_badge_issuer or field_badge_issuer_on_request.
 *
 * @group appointment_facilitator
 */
class IssuableBadgeSelectionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'taxonomy',
    'field',
    'text',
    'filter',
    'appointment_facilitator',
  ];

  /**
   * The entity reference selection plugin manager.
   *
   * @var \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface
   */
  protected $selectionManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');

    Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    $this->ensureTermUserReferenceField('field_badge_issuer');
    $this->ensureTermUserReferenceField('field_badge_issuer_on_request');

    // user 1 = super-user placeholder; selection handler must not bypass.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    $this->selectionManager = \Drupal::service('plugin.manager.entity_reference_selection');
  }

  /**
   * On-request-only facilitators must still expose their badges.
   */
  public function testOnRequestIssuerBadgesAreSelectable(): void {
    $scheduled = User::create(['name' => 'scheduled_fac', 'status' => 1]);
    $scheduled->save();
    $on_request = User::create(['name' => 'on_request_fac', 'status' => 1]);
    $on_request->save();

    // Badge A: regular issuer. Badge B: on-request issuer only.
    $badge_a = Term::create([
      'vid' => 'badges',
      'name' => 'Drill Press',
      'field_badge_issuer' => [['target_id' => $scheduled->id()]],
    ]);
    $badge_a->save();
    $badge_b = Term::create([
      'vid' => 'badges',
      'name' => 'Jointer',
      'field_badge_issuer_on_request' => [['target_id' => $on_request->id()]],
    ]);
    $badge_b->save();
    // Badge C: issued by neither facilitator.
    $badge_c = Term::create(['vid' => 'badges', 'name' => 'Unrelated']);
    $badge_c->save();

    // The on-request facilitator must see badge B (the regression), not A or C.
    $tids = $this->referenceableTidsForHost((int) $on_request->id());
    $this->assertContains((int) $badge_b->id(), $tids, 'On-request issuer badge is selectable.');
    $this->assertNotContains((int) $badge_a->id(), $tids);
    $this->assertNotContains((int) $badge_c->id(), $tids);

    // The regular issuer sees badge A only.
    $scheduled_tids = $this->referenceableTidsForHost((int) $scheduled->id());
    $this->assertContains((int) $badge_a->id(), $scheduled_tids);
    $this->assertNotContains((int) $badge_b->id(), $scheduled_tids);

    // Save-time validation accepts the on-request badge for that facilitator.
    $handler = $this->handlerForHost((int) $on_request->id());
    $valid = array_values(array_map('intval', $handler->validateReferenceableEntities([(int) $badge_b->id()])));
    $this->assertSame([(int) $badge_b->id()], $valid);
  }

  /**
   * With no facilitator chosen the widget falls back to all badges.
   */
  public function testNoFacilitatorShowsAllBadges(): void {
    $badge = Term::create(['vid' => 'badges', 'name' => 'Anything']);
    $badge->save();

    $this->pushRequest();
    $handler = $this->selectionManager->getInstance($this->handlerOptions());
    $refs = $handler->getReferenceableEntities();
    $tids = isset($refs['badges']) ? array_map('intval', array_keys($refs['badges'])) : [];
    $this->assertContains((int) $badge->id(), $tids);
  }

  /**
   * Returns referenceable badge term ids for a given facilitator host-uid.
   *
   * @return int[]
   *   Term ids.
   */
  protected function referenceableTidsForHost(int $host_uid): array {
    $handler = $this->handlerForHost($host_uid);
    $refs = $handler->getReferenceableEntities();
    return isset($refs['badges']) ? array_map('intval', array_keys($refs['badges'])) : [];
  }

  /**
   * Builds the selection handler with the request scoped to a facilitator.
   */
  protected function handlerForHost(int $host_uid) {
    $this->pushRequest(['host-uid' => (string) $host_uid]);
    return $this->selectionManager->getInstance($this->handlerOptions());
  }

  /**
   * Pushes a request (with a session) so KernelTestBase teardown stays happy.
   */
  protected function pushRequest(array $query = []): void {
    $request = Request::create('https://example.com/node/add/appointment', 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    \Drupal::requestStack()->push($request);
  }

  /**
   * Returns the selection handler options for the badge field.
   */
  protected function handlerOptions(): array {
    return [
      'target_type' => 'taxonomy_term',
      'handler' => 'appointment_facilitator_issuable_badge:taxonomy_term',
      'target_bundles' => ['badges' => 'badges'],
    ];
  }

  /**
   * Creates a multi-value term→user reference field on the badges vocabulary.
   */
  protected function ensureTermUserReferenceField(string $field_name): void {
    if (!FieldStorageConfig::loadByName('taxonomy_term', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'taxonomy_term',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
        'cardinality' => -1,
      ])->save();
    }
    if (!FieldConfig::loadByName('taxonomy_term', 'badges', $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'taxonomy_term',
        'bundle' => 'badges',
        'label' => $field_name,
        'settings' => ['handler' => 'default:user'],
      ])->save();
    }
  }

}
