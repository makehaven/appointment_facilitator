<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * One-shot import of D7 historical "star" flag rows on tool (item) nodes
 * into the D11 `tool_favorite` flag.
 *
 * Source data: `scripts/migrations/tool_stars_d7.csv`, produced by
 * `scripts/migrations/extract_d7_tool_stars.py`.
 *
 * Safe to re-run — already-flagged combinations are skipped.
 */
class ToolStarsImportCommands extends DrushCommands {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FlagServiceInterface $flagService,
    protected readonly Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Import historical D7 tool stars into the D11 tool_favorite flag.
   *
   * @command mh:import-tool-stars
   * @option csv  Path to the CSV file (default: scripts/migrations/tool_stars_d7.csv).
   * @option dry  Don't write, just report what would happen.
   * @usage drush mh:import-tool-stars
   * @usage drush mh:import-tool-stars --csv=/tmp/custom.csv --dry
   */
  public function importToolStars(array $options = ['csv' => NULL, 'dry' => FALSE]): int {
    $csv_path = $options['csv'] ?? (DRUPAL_ROOT . '/../scripts/migrations/tool_stars_d7.csv');
    if (!is_file($csv_path)) {
      $this->logger()->error("CSV not found at {$csv_path}");
      return 1;
    }
    $dry = !empty($options['dry']);

    $flag = $this->entityTypeManager->getStorage('flag')->load('tool_favorite');
    if (!$flag) {
      $this->logger()->error('tool_favorite flag does not exist. Run drush cim first.');
      return 1;
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $user_storage = $this->entityTypeManager->getStorage('user');

    $fh = fopen($csv_path, 'r');
    $header = fgetcsv($fh);
    if ($header !== ['entity_id', 'uid', 'timestamp']) {
      $this->logger()->error("Unexpected CSV header: " . implode(',', $header ?: []));
      fclose($fh);
      return 1;
    }

    $imported = 0;
    $already = 0;
    $missing_node = 0;
    $wrong_bundle = 0;
    $missing_user = 0;
    $errors = 0;

    while (($row = fgetcsv($fh)) !== FALSE) {
      [$entity_id, $uid, $timestamp] = $row;
      $entity_id = (int) $entity_id;
      $uid = (int) $uid;
      $timestamp = (int) $timestamp;

      $node = $node_storage->load($entity_id);
      if (!$node instanceof NodeInterface) {
        $missing_node++;
        continue;
      }
      if ($node->bundle() !== 'item') {
        $wrong_bundle++;
        continue;
      }
      $user = $user_storage->load($uid);
      if (!$user) {
        $missing_user++;
        continue;
      }

      // Skip if already flagged.
      if ($this->flagService->getFlagging($flag, $node, $user)) {
        $already++;
        continue;
      }

      if ($dry) {
        $imported++;
        continue;
      }

      try {
        $flagging = $this->flagService->flag($flag, $node, $user);
        // Preserve the original D7 timestamp on the flagging entity so the
        // recommendation ordering (most recently starred first) reflects
        // historical behavior rather than the import moment.
        if ($flagging && $flagging->hasField('created')) {
          $this->database->update('flagging')
            ->fields(['created' => $timestamp])
            ->condition('id', $flagging->id())
            ->execute();
        }
        $imported++;
      }
      catch (\Throwable $e) {
        $errors++;
        $this->logger()->warning(sprintf(
          'Failed to flag nid=%d uid=%d: %s',
          $entity_id,
          $uid,
          $e->getMessage()
        ));
      }
    }
    fclose($fh);

    $this->output()->writeln('');
    $this->output()->writeln(sprintf('  %s: %d', $dry ? 'Would import' : 'Imported', $imported));
    $this->output()->writeln(sprintf('  Already flagged (skipped): %d', $already));
    $this->output()->writeln(sprintf('  Missing node: %d', $missing_node));
    $this->output()->writeln(sprintf('  Wrong bundle: %d', $wrong_bundle));
    $this->output()->writeln(sprintf('  Missing user: %d', $missing_user));
    $this->output()->writeln(sprintf('  Errors: %d', $errors));
    return 0;
  }

}
