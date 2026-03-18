<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Url;

/**
 * Controller for printing badge checklists.
 */
class BadgePrintController extends ControllerBase {

  /**
   * Returns a bare-bones HTML page for printing.
   */
  public function print(TermInterface $taxonomy_term) {
    if ($taxonomy_term->bundle() !== 'badges') {
      return new Response('Invalid term bundle.', 404);
    }

    $title = $taxonomy_term->label();
    $checklist_items = [];
    
    if ($taxonomy_term->hasField('field_badge_checklist_items') && !$taxonomy_term->get('field_badge_checklist_items')->isEmpty()) {
      foreach ($taxonomy_term->get('field_badge_checklist_items') as $item) {
        $checklist_items[] = $item->value;
      }
    }

    $checklist_html = '';
    if (!empty($checklist_items)) {
      $checklist_html = '<ul class="standard-checklist">';
      foreach ($checklist_items as $item_text) {
        // Skip items that are just the title
        if (trim(strtolower($item_text)) === trim(strtolower($title))) {
          continue;
        }
        $checklist_html .= '<li style="list-style:none; margin-bottom: 10px; display: flex; align-items: flex-start; font-size: 14pt;"><input type="checkbox" style="width: 22px; height: 22px; margin-right: 15px; flex-shrink: 0; margin-top: 2px;"> ' . htmlspecialchars($item_text) . '</li>';
      }
      $checklist_html .= '</ul>';
    } else {
      $checklist_html = '<p>No checklist items found for this badge.</p>';
    }

    $date = date('F j, Y');
    $badge_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $taxonomy_term->id()], ['absolute' => TRUE])->toString();

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Print Checklist: $title</title>
  <style>
    body {
      font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
      line-height: 1.3;
      color: #000;
      padding: 15px;
      max-width: 950px;
      margin: 0 auto;
    }
    h1 {
      font-size: 22pt;
      border-bottom: 2px solid #000;
      padding-bottom: 5px;
      margin-bottom: 15px;
      text-align: center;
    }
    ul {
      list-style-type: none;
      padding-left: 10px;
    }
    .footer {
      margin-top: 30px;
      border-top: 1px solid #eee;
      padding-top: 10px;
      font-size: 8pt;
      color: #888;
      display: flex;
      justify-content: space-between;
    }
    @media print {
      body { padding: 0; }
      .no-print { display: none; }
    }
  </style>
</head>
<body onload="window.print()">
  <div class="no-print" style="margin-bottom: 15px; text-align: right;">
    <button onclick="window.print()" style="padding: 8px 16px; font-size: 12pt; cursor: pointer; background: #007bff; color: white; border: none; border-radius: 4px;">Print This Checklist</button>
  </div>
  
  <h1>$title Badge Checklist</h1>
  
  <div class="checklist-content">
    $checklist_html
  </div>

  <div class="footer">
    <span>Printed on $date</span>
    <span>Badge URL: $badge_url</span>
  </div>
</body>
</html>
HTML;

    return new Response($html);
  }

}
