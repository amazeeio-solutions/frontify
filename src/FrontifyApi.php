<?php

declare(strict_types = 1);

namespace Drupal\frontify;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Http\Client\ClientInterface;

/**
 * Executes Frontify GraphQL API queries.
 */
final class FrontifyApi {

  use StringTranslationTrait;

  /**
   *
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MessengerInterface $messenger,
    private readonly LoggerChannelFactory $loggerChannelFactory,
  ) {}

  /**
   *
   */
  public function frontifyQuery(string $query): array {
    // Default is a readonly token for Frontify API. Safe to use in code.
    // If more capabilities are needed, a separate token should be created.
    $config = $this->configFactory->get('frontify.settings');

    $token = $config->get('frontify_api_token');
    if (empty($token)) {
      $this->messenger->addError('Frontify API token is not set.');
    }

    $apiUrl = $config->get('frontify_api_url');
    if (empty($apiUrl)) {
      $this->messenger->addError('Frontify API url is not set.');
    }

    $result = [];
    try {
      $headers = [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
      ];
      $betaVersion = $config->get('frontify_api_beta') === 1;
      if ($betaVersion) {
        $headers['X-Frontify-Beta'] = 'enabled';
      }
      $response = $this->httpClient->post($apiUrl . '/graphql', [
        'headers' => $headers,
        'json' => [
          'query' => $query,
        ],
      ]);

      $result = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Exception $e) {
      $error = $this->t('Error: @message', ['@message' => $e->getMessage()]);
      $this->loggerChannelFactory->get('frontify')->error($error);
      $this->messenger->addError($error);
    }

    return $result;
  }

  /**
   * GraphQL query to get custom metadata for a Frontify asset.
   *
   * @param string $frontify_asset_id
   *
   * @return array
   */
  public function getCustomMetaData(string $frontify_asset_id): array {
    try {
      $query = 'query GetCustomMetadata {
        asset(id: "' . $frontify_asset_id . '") {
          id
          customMetadata {
            ... on CustomMetadataValue {
              property {
                id
                name
              }
              value
            }
          }
        }
      }';

      $queryResult = $this->frontifyQuery($query);
      if (!empty($queryResult['data']['asset']['customMetadata'])) {
        return $queryResult['data']['asset']['customMetadata'];
      }
    }
    catch (\Exception $e) {
      $error = $this->t('Error: @message', ['@message' => $e->getMessage()]);
      $this->loggerChannelFactory->get('frontify')->error($error);
      $this->messenger->addError($error);
    }

    return [];
  }

  /**
   * GraphQL query to get the Focal point.
   *
   * @param string $frontify_asset_id
   *
   * @return array|null
   */
  public function getFocalPoint(string $frontify_asset_id): ?array {
    try {
      $query = 'query GetFocalPoint {
        asset(id: "' . $frontify_asset_id . '") {
          ... on Image {
            focalPoint
          }
        }
      }';

      $queryResult = $this->frontifyQuery($query);
      if (!empty($queryResult['data']['asset']['focalPoint'])) {
        return $queryResult['data']['asset']['focalPoint'];
      }
    }
    catch (\Exception $e) {
      $error = $this->t('Error: @message', ['@message' => $e->getMessage()]);
      $this->loggerChannelFactory->get('frontify')->error($error);
      $this->messenger->addError($error);
    }

    return NULL;
  }

  /**
   * GraphQL query to get an Image alt text.
   *
   * Beta version only.
   *
   * @param string $frontify_asset_id
   *
   * @return string|null
   */
  public function getImageAltText(string $frontify_asset_id): ?string {
    $config = $this->configFactory->get('frontify.settings');
    if ($config->get('frontify_api_beta') !== 1) {
      \Drupal::logger('frontify')->warning($this->t('Alt text query can currently be used with Beta API only. This can be changed in Frontify settings form.'));
      return NULL;
    }

    try {
      $query = 'query GetImageAltText {
        asset(id: "' . $frontify_asset_id . '") {
          id
          ... on Image {
            alternativeText
          }
        }
      }';

      $queryResult = $this->frontifyQuery($query);
      if (!empty($queryResult['data']['asset']['alternativeText'])) {
        return $queryResult['data']['asset']['alternativeText'];
      }
    }
    catch (\Exception $e) {
      $error = $this->t('Error: @message', ['@message' => $e->getMessage()]);
      $this->loggerChannelFactory->get('frontify')->error($error);
      $this->messenger->addError($error);
    }

    return NULL;
  }

}
