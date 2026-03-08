<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\ClientInterface;

/**
 * Service for sending Slack notifications related to appointments.
 */
class AppointmentSlackService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  public function __construct(ClientInterface $httpClient, ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $loggerFactory) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->logger = $loggerFactory->get('appointment_facilitator');
  }

  /**
   * Sends a message to a specific user via Slack.
   *
   * Note: This currently uses the webhook and relies on Slack's matching
   * of @username if supported by the webhook configuration, or posts to a
   * general channel.
   */
  public function sendMessageToUser(AccountInterface $user, string $message): bool {
    $webhook_url = $this->configFactory->get('slack_connector.settings')->get('webhook_url');
    if (empty($webhook_url)) {
      $this->logger->error('Slack webhook URL is not configured.');
      return FALSE;
    }

    $slack_id = $this->getUserSlackId($user);
    $text = $message;
    if ($slack_id) {
      $text = "<@{$slack_id}>: " . $text;
    }

    try {
      $this->httpClient->post($webhook_url, [
        'json' => [
          'text' => $text,
          'link_names' => 1,
        ],
      ]);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send Slack message: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Resolves the Slack ID for a user.
   */
  protected function getUserSlackId(AccountInterface $user): ?string {
    if (!\Drupal::moduleHandler()->moduleExists('profile')) {
      return NULL;
    }

    $profiles = \Drupal::entityTypeManager()->getStorage('profile')->loadByUser($user, 'main');
    $profile = is_array($profiles) ? reset($profiles) : $profiles;
    if ($profile && $profile->hasField('field_member_slack_id_number') && !$profile->get('field_member_slack_id_number')->isEmpty()) {
      return (string) $profile->get('field_member_slack_id_number')->value;
    }

    return NULL;
  }

}
