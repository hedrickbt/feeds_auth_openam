<?php

/**
 * @file
 * Contains \Drupal\feeds\EventSubscriber\LazySubscriber.
 */

namespace Drupal\feeds_auth_openam\EventSubscriber;

use Drupal\feeds\Event\ClearEvent;
use Drupal\feeds\Event\ExpireEvent;
use Drupal\feeds\Event\FeedsEvents;
use Drupal\feeds\Event\FetchEvent;
use Drupal\feeds\Event\InitEvent;
use Drupal\feeds\Event\ParseEvent;
use Drupal\feeds\Event\ProcessEvent;
use Drupal\feeds\Plugin\Type\ClearableInterface;
use Drupal\feeds\StateInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener that registers Feeds pluings as event listeners.
 */
class LazySubscriber extends \Drupal\feeds\EventSubscriber\LazySubscriber implements EventSubscriberInterface {
  /**
   * Adds import plugins as event listeners.
   */
  public function onInitImport(InitEvent $event, $event_name, EventDispatcherInterface $dispatcher) {
    $stage = $event->getStage();

    if (isset($this->importInited[$stage])) {
      return;
    }
    $this->importInited[$stage] = TRUE;

    switch ($stage) {
      case 'fetch':
        $dispatcher->addListener(FeedsEvents::FETCH, function(FetchEvent $event) {
          $feed = $event->getFeed();
          $result = $feed->getType()->getFetcher()->fetch($feed, $feed->getState(StateInterface::FETCH));
          $event->setFetcherResult($result);
        });
        break;

      case 'parse':
        $dispatcher->addListener(FeedsEvents::PARSE, function(ParseEvent $event) {
          $feed = $event->getFeed();

          $result = $feed
            ->getType()
            ->getParser()
            ->parse($feed, $event->getFetcherResult(), $feed->getState(StateInterface::PARSE));
          $event->setParserResult($result);
        });
        break;

      case 'process':
        $dispatcher->addListener(FeedsEvents::PROCESS, function(ProcessEvent $event) {
          $feed = $event->getFeed();
          $feed
            ->getType()
            ->getProcessor()
            ->process($feed, $event->getParserResult(), $feed->getState(StateInterface::PROCESS));
        });
        break;
    }
  }
}
