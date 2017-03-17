<?php

/**
 * @file
 * Contains \Drupal\test_api\Controller\TestAPIController.
 */

/*
Exemple :
http://192.168.0.114/aveyron-pierre/api/enss?conditions=[{%22field%22:%22nid%22,%22value%22:[2,4],%22operator%22:%22in%22}]
*/

namespace Drupal\aveyron_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\geoPHP;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AveyronAPIController extends ControllerBase {

  public function vidsAction( Request $request ) {
    $entityManager = \Drupal::entityManager();

    $types = array('ens', 'taxon');
    $result = array();
    foreach ($types as $type) {
      $query = \Drupal::entityQuery('node');
      $query->condition('status', 1);
      $query->condition('type', $type);
      $ids = $query->execute();
      $entities = $entityManager->getStorage('node')->loadMultiple($ids);
      $items = array();
      foreach ($entities as $entity) {
        $items[] = array(
          id => (int) $entity->nid->value,
          vid => (int) $entity->vid->value
        );
      }
      $result[$type] = $items;
    }

    return new JsonResponse($result);
  }

  public function enss( Request $request ) {
    $fullItemIds = $request->get('fullItemIds');
    if (is_string($fullItemIds)) {
        $fullItemIds = json_decode($fullItemIds, true);
    }
    foreach ($fullItemIds as &$fullItemId) {
      if (is_string($fullItemId)) {
        $fullItemId = (int) $fullItemId;
      }
    }

    $query = \Drupal::entityQuery('node');
    $query->condition('status', 1);
    $query->condition('type', 'ens');

    $conditions = $request->get('conditions');
    if (is_string($conditions)) {
        $conditions = json_decode($conditions, true);
    }
    foreach ($conditions as $key => $condition) {
      $query->condition($condition['field'], $condition['value'], $condition['operator'] ? $condition['operator'] : '=');
    }

    $itemIds = $query->execute();

    $entityManager = \Drupal::entityManager();
    $entities = $entityManager->getStorage('node')->loadMultiple($itemIds);

    $serializer = \Drupal::service('serializer');
    $items = array();
    $taxonIds = array();
    foreach ($entities as $entity) {
      $item = array();
      //$thumbnail = $entity->field_thumbnail->entity;
      $poster = $entity->field_poster->entity;
      $geom = \geoPHP::load($entity->field_start_trace->value,'wkt');
      $itemId = (int) $entity->nid->value;
      $item = array(
        //a => json_decode($serializer->serialize($thumbnail, 'json', ['plugin_id' => 'entity'])),
        id => $itemId,
        vid => (int) $entity->vid->value,
        title => $entity->title->value,
        startTrace => $geom ? json_decode($geom->out('json'), true) : null,
        /*
        thumbnail => array(
          fid => $thumbnail->fid->value,
          url => file_create_url($thumbnail->uri->value),
          filesize => $thumbnail->filesize->value,
        ),
        */
        poster => array(
          fid => $poster->fid->value,
          url => file_create_url($poster->uri->value),
          filesize => $poster->filesize->value,
        ),
        descriptionShort => substr($entity->body->summary, 0, 255),
      );
      if (in_array($itemId, $fullItemIds)) {
        $item['description'] = $entity->body->value;
        $item['taxonIds'] = array();
        $taxa = $entity->field_taxa;
        foreach ($taxa as $taxon) {
          $item['taxonIds'][] = (int) $taxon->target_id;
        }
      }
      //$item = $serializer->serialize($entity, 'json', ['plugin_id' => 'entity']);
      $items[] = $item;
    }

    $response = new JsonResponse($items);

    //$response->headers->set('Access-Control-Allow-Origin', '*');

    return $response;
  }

  public function ens( $id, Request $request ) {

    $entityManager = \Drupal::entityManager();
    $entity = $entityManager->getStorage('node')->load($id);

    if (!$entity->nid->value || $entity->getType() != 'ens') {
      return new Response(null, 404);
    }
    $serializer = \Drupal::service('serializer');
    /*$query = db_query("
      SELECT f.uri, g.field_gallery_alt,
      g.field_gallery_title
      from file_managed f
      join node__field_gallery g
      on f.fid = g.field_gallery_target_id
      where g.entity_id = $id limit 1"
    );

    $poster = $query->fetchAll();
    $poster[0]->uri = entity_load('image_style', '500_par_350')->buildUrl($poster[0]->uri);
    */

    $geom = \geoPHP::load($entity->field_start_trace->value,'wkt');
    $geomTrace = \geoPHP::load($entity->field_trace->value,'wkt');
    $result = array(
      id => (int) $entity->nid->value,
      vid => (int) $entity->vid->value,
      title => $entity->title->value,
      startPoint => json_decode($geom->out('json'), true),
      trace => json_decode($geomTrace->out('json'), true),
      /*poster => array(
        url => $poster[0]->uri
      ),*/
      /*thumbnail => array(
        fid => $thumbnail->fid->value,
        url => file_create_url($thumbnail->uri->value),
        filesize => $thumbnail->filesize->value,
      ),
      */
      description => $entity->body->value,
      descriptionShort => substr($entity->body->summary, 0, 255),
      gallery => array(),
      taxonIds => array(),
    );

    $gallery = $entity->field_gallery;
    foreach ($gallery as $img) {
      //TODO
      $imgData = json_decode($serializer->serialize($img, 'json', ['plugin_id' => 'entity']));
      $result['gallery'][] = array(
        fid => $imgData->target_id,
        url => $imgData->url,
      );
      //$result['gallery'][] = json_decode($serializer->serialize($img, 'json', ['plugin_id' => 'entity']));
    }
    $taxa = $entity->field_taxa;
    foreach ($taxa as $taxon) {
      $result['taxonIds'][] = (int) $taxon->target_id;
    }

    return new JsonResponse($result);
  }

  public function ensQuiz( $id, Request $request ) {
    $serializer = \Drupal::service('serializer');
    $entityManager = \Drupal::entityManager();

    $entity = $entityManager->getStorage('node')->load($id);
    if (!$entity->nid->value || $entity->getType() != 'ens') {
      return new Response(null, 404);
    }

    $questionIds = array();
    foreach ($entity->field_quiz as $question) {
      $questionIds[] = $question->target_id;
    }
    $entities = $entityManager->getStorage('node')->loadMultiple($questionIds);
    $result = array(
      questions => array()
    );
    foreach ($entities as $question) {
      $answers = array();
      foreach ($question->field_answers as $answer) {
        $answers[] = $answer->value;
      }
      $result['questions'][] = array(
        id => (int) $question->nid->value,
        vid => (int) $question->vid->value,
        title => $question->title->value,
        poster => file_create_url($question->field_poster->entity->uri->value),
        goodAnswer => (int) $question->field_good_answer[0]->value,
        answers => $answers,
      );
    }

    return new JsonResponse($result);
  }

  public function taxa( Request $request ) {
    $query = \Drupal::entityQuery('node');
    $query->condition('status', 1);
    $query->condition('type', 'taxon');

    $conditions = $request->get('conditions');
    if (is_string($conditions)) {
        $conditions = json_decode($conditions, true);
    }
    foreach ($conditions as $key => $condition) {
      $query->condition($condition['field'], $condition['value'], $condition['operator'] ? $condition['operator'] : '=');
    }

    try {
      $taxaIds = $query->execute();
    } catch (Exception $e) {
      return new JsonResponse($e);
    }

    $entityManager = \Drupal::entityManager();
    $entities = $entityManager->getStorage('node')->loadMultiple($taxaIds);

    $taxa = array();
    $serializer = \Drupal::service('serializer');
    foreach ($entities as $entity) {
      //$thumbnail = $entity->field_thumbnail->entity;
      $poster = $entity->field_poster->entity;
      $item = array(
        id => (int) $entity->nid->value,
        vid => (int) $entity->vid->value,
        title => $entity->title->value,
        /*thumbnail => array(
          fid => $thumbnail->fid->value,
          url => file_create_url($thumbnail->uri->value),
          filesize => $thumbnail->filesize->value,
        ),*/
        poster => array(
          fid => $poster->fid->value,
          url => file_create_url($poster->uri->value),
          filesize => $poster->filesize->value,
        ),
        description => $entity->body->summary,
        descriptionShort => substr($entity->body->summary, 0, 255),
        gallery => array(),
      );
      $gallery = $entity->field_gallery;
      foreach ($gallery as $img) {
        //TODO
        $imgData = json_decode($serializer->serialize($img, 'json', ['plugin_id' => 'entity']));
        $item['gallery'][] = array(
          fid => $imgData->target_id,
          url => $imgData->url,
        );
        //$result['gallery'][] = json_decode($serializer->serialize($img, 'json', ['plugin_id' => 'entity']));
      }
      $taxa[] = $item;
    }

    return new JsonResponse($taxa);
  }
}
