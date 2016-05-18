<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Fetcher\HttpFetcherAuth.
 */

namespace Drupal\feeds_auth_openam\Feeds\Fetcher;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Feeds\Fetcher\HttpFetcher;
use Drupal\feeds\Plugin\Type\ClearableInterface;
use Drupal\feeds\Plugin\Type\FeedPluginFormInterface;
use Drupal\feeds\Plugin\Type\Fetcher\FetcherInterface;
use Drupal\feeds\StateInterface;
use Drupal\key\KeyRepository;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defines an HTTP fetcher.
 *
 * @FeedsFetcher(
 *   id = "http_auth_openam",
 *   title = @Translation("Download Auth OpenAM"),
 *   description = @Translation("Downloads data from a URL using Drupal's HTTP request handler with authentication for OpenAM."),
 *   configuration_form = "Drupal\feeds\Feeds\Fetcher\Form\HttpFetcherForm",
 *   arguments = {"@http_client", "@cache.feeds_download", "@file_system", "@serialization.json", "@logger.factory", "@key.repository"}
 * )
 */
class HttpFetcherAuthOpenAM extends HttpFetcher implements ClearableInterface, FeedPluginFormInterface, FetcherInterface
{
    const OPENAM_LOGIN_URI = '/openam/json/authenticate?module=DataStore&authIndexType=module&authIndexValue=DataStore';
    const OPENAM_LOGOUT_URI = '/openam/json/sessions/?_action=logout';
    const OPENAM_ADVANCED_LOGIN_FEED_REQUEST_COOKIE_NAME = 'iPlanetDirectoryPro';
    const OPENAM_ADVANCED_LOGIN_USERNAME_HEADER = 'X-OpenAM-Username';
    const OPENAM_ADVANCED_LOGIN_PASSWORD_HEADER = 'X-OpenAM-Password';
    const OPENAM_ADVANCED_LOGIN_JSON_RESPONSE_SESSION_ID = 'tokenId';
    const OPENAM_ADVANCED_LOGIN_USER_AGENT = 'Drupal/Feeds/HttpFetcher/1.0';
    const OPENAM_ADVANCED_LOGOUT_JSON_SUCCESS_SEARCH_TEXT = 'Success';
    const OPENAM_ADVANCED_LOGOUT_SESSION_ID_HEADER = 'iplanetDirectoryPro';

    /**
     * @var \Drupal\feeds\FeedInterface
     * This will only be set during the fetch phase
     */
    protected $feed;

    /**
     * @var \Drupal\Component\Serialization\SerializationInterface
     */
    protected $serialization_json;


    /**
     * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
     */
    protected $loggerChannelFactory;

    /**
     * @var \Drupal\key\KeyRepository
     */
    protected $keyRepository;

    /**
     * HttpFetcherAuth constructor.
     * @param array $configuration
     * @param string $plugin_id
     * @param array $plugin_definition
     * @param ClientInterface $client
     * @param CacheBackendInterface $cache
     * @param FileSystemInterface $file_system
     * @param SerializationInterface $serialization_json
     * @param LoggerChannelFactoryInterface $loggerChannelFactory
     * @param KeyRepository $keyRepository
     */
    public function __construct(
        array $configuration,
        $plugin_id,
        array $plugin_definition,
        ClientInterface $client,
        CacheBackendInterface $cache,
        FileSystemInterface $file_system,
        SerializationInterface $serialization_json,
        LoggerChannelFactoryInterface $loggerChannelFactory,
        KeyRepository $keyRepository)
    {

        parent::__construct($configuration, $plugin_id, $plugin_definition, $client, $cache, $file_system, $loggerChannelFactory);
        $this->serialization_json = $serialization_json;
        $this->loggerChannelFactory = $loggerChannelFactory;
        $this->keyRepository = $keyRepository;
    }

    /**
     * Performs a GET request.
     *
     * @param string $url
     *   The URL to GET.
     * @param string $cache_key
     *   (optional) The cache key to find cached headers. Defaults to false.
     *
     * @return \Guzzle\Http\Message\Response
     *   A Guzzle response.
     *
     * @throws \RuntimeException
     *   Thrown if the GET request failed.
     */
    protected function get($url, $sink, $cache_key = FALSE)
    {
        $feed_config = $this->feed->getConfigurationFor($this);

        $url = strtr($url, [
            'feed://' => 'http://',
            'webcal://' => 'http://',
            'feeds://' => 'https://',
            'webcals://' => 'https://',
        ]);

        $login_response = $this->login($url, $this->feed);

        $options = [
            RequestOptions::SINK => $sink,
            RequestOptions::HEADERS => [
                'Cookie' => 'iPlanetDirectoryPro=' . $login_response[$feed_config['openam']['advanced_login']['json_response_session_id']]
            ]
        ];

        // Add cached headers if requested.
        if ($cache_key && ($cache = $this->cache->get($cache_key))) {
            if (isset($cache->data['etag'])) {
                $options[RequestOptions::HEADERS]['If-None-Match'] = $cache->data['etag'];
            }
            if (isset($cache->data['last-modified'])) {
                $options[RequestOptions::HEADERS]['If-Modified-Since'] = $cache->data['last-modified'];
            }
        }

        try {
            $response = $this->client->get($url, $options);
        } catch (RequestException $e) {
            $args = ['%site' => $url, '%error' => $e->getMessage()];
            throw new \RuntimeException($this->t('The feed from %site seems to be broken because of error "%error".', $args));
        }

        if ($cache_key) {
            $this->cache->set($cache_key, array_change_key_case($response->getHeaders()));
        }


        $this->logout($url, $login_response, $this->feed);


        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function buildFeedForm(array $form, FormStateInterface $form_state, FeedInterface $feed)
    {
        $form = parent::buildFeedForm($form, $form_state, $feed);

        $feed_config = $feed->getConfigurationFor($this);


        $form['openam'] = array(
            '#type' => 'details',
            '#title' => $this->t('OpenAM'),
            '#open' => TRUE,
        );

        // [login] section
        $form['openam']['login'] = array(
            '#type' => 'details',
            '#title' => $this->t('Login'),
            '#open' => TRUE,
        );
        $form['openam']['login']['uri'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('URL'),
            '#default_value' => $feed_config['openam']['login']['uri'],
            '#size' => 80,
            '#description' => $this->t('Specify the Web Service URL, that supports JSON, to log into the OpenAM server.  Ex:<br>' .
                '&nbsp;https://yourserver' . self::OPENAM_LOGIN_URI . ' <b>or</b><br> ' .
                '&nbsp;'.self::OPENAM_LOGIN_URI),
        );
        $form['openam']['login']['username'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('Username'),
            '#default_value' => $feed_config['openam']['login']['username'],
            '#size' => 40,
            '#description' => $this->t('OpenAM username'),
        );
        $form['openam']['login']['password'] = [
            '#type' => 'key_select',
            '#title' => $this->t('Password'),
            '#default_value' => $feed_config['openam']['login']['password'],
            '#description' => $this->t('OpenAM password'),
        ];

        // [logout] section
        $form['openam']['logout'] = array(
            '#type' => 'details',
            '#title' => $this->t('Logout'),
            '#open' => TRUE,
        );
        $form['openam']['logout']['uri'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('URL'),
            '#default_value' => $feed_config['openam']['logout']['uri'],
            '#size' => 80,
            '#description' => $this->t('Specify the Web Service URL, that is used to logout from an OpenAM server.  Ex:<br>' .
                '&nbsp;https://yourserver' . self::OPENAM_LOGOUT_URI . ' <b>or</b><br> ' .
                '&nbsp;' . self::OPENAM_LOGOUT_URI),
        );


        // [advanced_login] section
        $form['openam']['advanced_login'] = array(
            '#type' => 'details',
            '#title' => $this->t('Advanced Login'),
            '#open' => FALSE,
        );
        $form['openam']['advanced_login']['feed_request_cookie_name'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('Feed Request Cookie Name'),
            '#default_value' => $feed_config['openam']['advanced_login']['feed_request_cookie_name'],
            '#size' => 40,
            '#description' => $this->t('The name of the OpenAM cookie that is used to authenticate a user after they have ' .
                'logged in to an OpenAM server.  This cookie needs to be passed when requesting the feed.  The default ' .
                'value is ' . self::OPENAM_ADVANCED_LOGIN_FEED_REQUEST_COOKIE_NAME),
        );
        $form['openam']['advanced_login']['username_header'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('Username Header'),
            '#default_value' => $feed_config['openam']['advanced_login']['username_header'],
            '#size' => 40,
            '#description' => $this->t('The name of the header passed to OpenAM that contains the username when making '.
                'a json login request.  The default value is ' . self::OPENAM_ADVANCED_LOGIN_USERNAME_HEADER),
        );
        $form['openam']['advanced_login']['password_header'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('Password Header'),
            '#default_value' => $feed_config['openam']['advanced_login']['password_header'],
            '#size' => 40,
            '#description' => $this->t('The name of the header passed to OpenAM that contains the password when making '.
                'a json login request.  The default value is ' . self::OPENAM_ADVANCED_LOGIN_PASSWORD_HEADER),
        );
        $form['openam']['advanced_login']['json_response_session_id'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('Json Response Session Id'),
            '#default_value' => $feed_config['openam']['advanced_login']['json_response_session_id'],
            '#size' => 40,
            '#description' => $this->t('The name of the json attribute that OpenAM returns with the session id when making '.
                'a json login request.  The default value is ' . self::OPENAM_ADVANCED_LOGIN_JSON_RESPONSE_SESSION_ID),
        );
        $form['openam']['advanced_login']['user_agent'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('User-Agent'),
            '#default_value' => $feed_config['openam']['advanced_login']['user_agent'],
            '#size' => 40,
            '#description' => $this->t('The value of the User-Agent header passed to the feed.  This is useful when '.
                'looking at web server access logs or when controlling access to a feed if you wish to restrict ' .
                'access by User-Agent.  The default value is: ' . self::OPENAM_ADVANCED_LOGIN_USER_AGENT ),
        );

        // [advanced_logout] section
        $form['openam']['advanced_logout'] = array(
            '#type' => 'details',
            '#title' => $this->t('Advanced Logout'),
            '#open' => FALSE,
        );
        $form['openam']['advanced_logout']['session_id_header'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('Session Id Header'),
            '#default_value' => $feed_config['openam']['advanced_logout']['session_id_header'],
            '#size' => 40,
            '#description' => $this->t('The name of the session id header passed to OpenAM when logging out.  '.
                'The default value is ' . self::OPENAM_ADVANCED_LOGOUT_SESSION_ID_HEADER ),
        );
        $form['openam']['advanced_logout']['json_success_search_text'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('Successful Result Search Text'),
            '#default_value' => $feed_config['openam']['advanced_logout']['json_success_search_text'],
            '#size' => 40,
            '#description' => $this->t('The value to search for during logout from OpenAM to make sure the logout was '.
                'successful by looking in json response.  The default value is: ' .
                self::OPENAM_ADVANCED_LOGOUT_JSON_SUCCESS_SEARCH_TEXT),
        );

        return $form;
    }


    /**
     * @return array
     */
    public function sourceDefaults() {
        // Need to create the array slots to map the form fields into via the setConfigurationFor method intersect.
        // This is also the place to provide any default values.
        $result = array();
        $result['openam'] = array();

        $result['openam']['login'] = array();
        $result['openam']['login']['uri'] = self::OPENAM_LOGIN_URI;

        $result['openam']['logout'] = array();
        $result['openam']['logout']['uri'] = self::OPENAM_LOGOUT_URI;

        $result['openam']['advanced_login'] = array();
        $result['openam']['advanced_login']['feed_request_cookie_name'] = self::OPENAM_ADVANCED_LOGIN_FEED_REQUEST_COOKIE_NAME;
        $result['openam']['advanced_login']['username_header'] = self::OPENAM_ADVANCED_LOGIN_USERNAME_HEADER;
        $result['openam']['advanced_login']['password_header'] = self::OPENAM_ADVANCED_LOGIN_PASSWORD_HEADER;
        $result['openam']['advanced_login']['json_response_session_id'] = self::OPENAM_ADVANCED_LOGIN_JSON_RESPONSE_SESSION_ID;
        $result['openam']['advanced_login']['user_agent'] = self::OPENAM_ADVANCED_LOGIN_USER_AGENT;

        $result['openam']['advanced_logout'] = array();
        $result['openam']['advanced_logout']['json_success_search_text'] = self::OPENAM_ADVANCED_LOGOUT_JSON_SUCCESS_SEARCH_TEXT;
        $result['openam']['advanced_logout']['session_id_header'] = self::OPENAM_ADVANCED_LOGOUT_SESSION_ID_HEADER;

        return $result;
    }


    /**
     * {@inheritdoc}
     */
    public function submitFeedForm(array &$form, FormStateInterface $form_state, FeedInterface $feed) {
        $feed->setConfigurationFor($this, $form_state->getValues());
        parent::submitFeedForm($form, $form_state, $feed);
    }


    /**
     * {@inheritdoc}
     */
    public function fetch(FeedInterface $feed, StateInterface $state) {
        $this->feed = $feed;
        return parent::fetch($feed, $state);
    }


    /**
     * @param $url
     * @param $feed FeedInterface
     */
    protected function login($url, $feed)
    {
        $feed_config = $feed->getConfigurationFor($this);
        $login_response = null;

        try {
            $login_request = $this->client->request(
                'POST',
                $feed_config['openam']['login']['uri'],
                [
                    RequestOptions::HEADERS => [
                        'User-Agent' => $feed_config['openam']['advanced_login']['user_agent'],
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        $feed_config['openam']['advanced_login']['username_header'] => $feed_config['openam']['login']['username'],
                        $feed_config['openam']['advanced_login']['password_header'] =>
                            $this->keyRepository->getKey($feed_config['openam']['login']['password'])->getKeyValue()
                    ]
                ]
            );

            if (($login_request->getStatusCode() != Response::HTTP_OK) &&
                (strpos($login_request->getBody(), $feed_config['openam']['json_response_session_id']) !== false)
            ) {
                $args = [
                    '%site' => $url,
                    '%error' => $login_request->getStatusCode(),
                    '%error_message' => $login_request->getReasonPhrase()
                ];
                $this->loggerChannelFactory->get('default')->error(
                    $this->t('Unable to log into feed %site it seems to be broken because of error "%error/%error_message".', $args)
                );
                throw new \RuntimeException(
                    $this->t('Unable to log into feed %site it seems to be broken because of error "%error/%error_message".', $args)
                );
            } else {
                $login_response = $this->serialization_json->decode((string)$login_request->getBody());
            }
        } catch (RequestException $e) {
            $args = ['%site' => $url, '%error' => $e->getMessage()];
            $this->loggerChannelFactory->get('default')->error(
                $this->t('Unable to log into the feed %site it seems to be broken because of error "%error".', $args)
            );
            throw new \RuntimeException(
                $this->t('Unable to log into the feed %site it seems to be broken because of error "%error".', $args)
            );
        }


        return $login_response;
    }


    /**
     * @param $url
     * @param $login_response
     * @param $feed FeedInterface
     * @return mixed
     */
    protected function logout($url, $login_response, $feed)
    {
        $feed_config = $feed->getConfigurationFor($this);
        $logout_response = null;

        try {
            $logout_request = $this->client->request(
                'POST',
                $feed_config['openam']['logout']['uri'],
                [
                    RequestOptions::HEADERS => [
                        'User-Agent' =>  $feed_config['openam']['advanced_login']['user_agent'],
                        'Accept' => 'application/json',
                        $feed_config['openam']['advanced_logout']['session_id_header'] => $login_response[ $feed_config['openam']['advanced_login']['json_response_session_id']]
                    ]
                ]
            );

            if (($logout_request->getStatusCode() != Response::HTTP_OK) &&
                (strpos($logout_request->getBody(), $feed_config['openam']['advanced_logout']['json_success_search_text']) !== false)
            ) {
                $args = [
                    '%site' => $url,
                    '%error' => $logout_request->getStatusCode(),
                    '%error_message' => $logout_request->getReasonPhrase()
                ];
                $this->loggerChannelFactory->get('default')->warning(
                    $this->t('Unable to log out of feed %site it seems to be broken because of error "%error/%error_message".', $args)
                );
            } else {
                $logout_response = $this->serialization_json->decode((string)$logout_request->getBody());
            }
        } catch (RequestException $e) {
            $args = ['%site' => $url, '%error' => $e->getMessage()];
            $this->loggerChannelFactory->get('default')->warning(
                $this->t('Unable to log out of feed %site it seems to be broken because of error "%error".', $args)
            );
        }

        return $logout_response;
    }
}

