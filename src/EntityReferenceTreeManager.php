<?php

namespace Drupal\entity_reference_tree;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;

/**
 * The Entity Reference Tree Manager class.
 */
class EntityReferenceTreeManager implements EntityReferenceTreeManagerInterface {

  /**
   * The database abstraction layer object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * An array of root entities, keyed by child entity data.
   *
   * @var array
   */
  protected $rootEntities = [];

  /**
   * An array of parent entities, keyed by child entity data.
   *
   * @var array
   */
  protected $isChildEntity = [];

  /**
   * An array of published ancestral parent content.
   *
   * @var array
   */
  protected $belongsToPublishedContent = [];

  /**
   * An array of entity reference fields by entity type & bundle.
   *
   * @var array
   */
  protected $entityReferenceFieldMap = [];

  /**
   * EntityReferenceTreeManager constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database abstraction layer object.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function belongsToPublishedContent(ContentEntityInterface $entity) {
    // @todo: Investigate and review for performance, if needed.
    if (!isset($this->belongsToPublishedContent[$entity->getEntityType()->id()])) {
      $this->belongsToPublishedContent[$entity->getEntityType()->id()] = [];
    }

    if (!isset($this->belongsToPublishedContent[$entity->getEntityType()->id()][$entity->id()])) {
      $this->belongsToPublishedContent[$entity->getEntityType()->id()][$entity->id()] = [];
      $entity_type = $entity->getEntityType();
      foreach ($this->getParentEntities($entity) as $root_entity_type => $root_entities_of_type) {
        if (empty($root_entities_of_type['items'])) {
          continue;
        }

        $_entity_type = $root_entities_of_type['_entity_type'];
        foreach ($root_entities_of_type['items'] as $root_entity_id => $root_entity_versions) {
          if ($_entity_type->isRevisionable()) {
            foreach ($root_entity_versions as $root_version_id => $root_entity) {
              if (!$root_entity->isDefaultRevision()) {
                continue;
              }

              if (($root_entity instanceof EntityPublishedInterface) && $root_entity->isPublished()) {
                $this->belongsToPublishedContent[$entity->getEntityType()
                  ->id()][$entity->id()][] = $root_entity;
              }
            }
          }
          elseif (!$_entity_type->isRevisionable() && !empty($root_entity_versions[0])) {
            $root_entity = $root_entity_versions[0];
            if (($root_entity instanceof EntityPublishedInterface) && $root_entity->isPublished()) {
              $this->belongsToPublishedContent[$entity->getEntityType()
                ->id()][$entity->id()][] = $root_entity;
            }
          }
        }
      }
    }
    return !empty($this->belongsToPublishedContent[$entity->getEntityType()->id()][$entity->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getParentPublishedContent(ContentEntityInterface $entity) {
    $this->belongsToPublishedContent($entity);
    return $this->belongsToPublishedContent[$entity->getEntityType()->id()][$entity->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function getParentEntities(ContentEntityInterface $entity) {
    $this->hasParentEntities($entity);
    return $this->isChildEntity[$entity->getEntityType()->id()][$entity->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function hasParentEntities(ContentEntityInterface $entity, $return_parents = FALSE) {
    // @todo: Investigate and review for performance, if needed.
    if (!isset($this->isChildEntity[$entity->getEntityType()->id()])) {
      $this->isChildEntity[$entity->getEntityType()->id()] = [];
    }

    if (!isset($this->isChildEntity[$entity->getEntityType()->id()][$entity->id()])) {
      $this->isChildEntity[$entity->getEntityType()->id()][$entity->id()] = FALSE;
      $exists_query = $this->database->select('entity_reference_tree', 't');
      $select_fields = [
        'id',
        'entity_type',
        'entity_bundle',
        'entity_id',
        'entity_version_id',
      ];
      $exists_query->fields('t', $select_fields)
        ->condition('t.referenced_entity_type', $entity->getEntityType()->id())
        ->condition('t.referenced_entity_bundle', $entity->bundle())
        ->condition('t.referenced_entity_id', $entity->id())
        ->condition('t.referenced_entity_version_id', $entity->getRevisionId());
      $exists_results = $exists_query->execute();

      $parent_entities = [];
      while ($result = $exists_results->fetchObject()) {
        $storage = $this->entityTypeManager->getStorage($result->entity_type);
        $entity_type = $this->entityTypeManager->getDefinition($result->entity_type);

        if (!isset($parent_entities[$entity_type->id()])) {
          $parent_entities[$entity_type->id()] = [
            '_entity_type' => $entity_type,
            'items' => [],
          ];
        }

        if ($entity_type->isRevisionable()) {
          $parent_entity = $storage->loadRevision($result->entity_version_id);
          if (!empty($parent_entity)) {
            $parent_entities[$entity_type->id()]['items'][$parent_entity->id()][$result->entity_version_id] = $parent_entity;
          }
        }
        else {
          $parent_entity = $storage->load($result->entity_id);
          if (!empty($parent_entity)) {
            $parent_entities[$entity_type->id()]['items'][$parent_entity->id()][0] = $parent_entity;
          }
        }
      }
      $this->isChildEntity[$entity->getEntityType()->id()][$entity->id()] = $parent_entities;
    }

    if (!empty($this->isChildEntity[$entity->id()][$entity->id()])) {
      return $return_parents ? $this->isChildEntity[$entity->id()][$entity->id()] : TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRootEntities(ContentEntityInterface $entity) {
    if (!isset($this->rootEntities[$entity->getEntityType()->id()])) {
      $this->rootEntities[$entity->getEntityType()->id()] = [];
    }

    if (!isset($this->rootEntities[$entity->getEntityType()->id()][$entity->id()])) {
      $this->rootEntities[$entity->getEntityType()->id()][$entity->id()] = $this->buildRootEntities($entity);
    }

    return $this->rootEntities[$entity->getEntityType()->id()][$entity->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function getExistingStoredReferencesByField(ContentEntityInterface $entity, $field_name) {
    // @todo: Investigate and review for performance, if needed.
    $return = [];
    $select_query = $this->database->select('entity_reference_tree', 't');
    $select_query->fields('t')
      ->condition('t.entity_type', $entity->getEntityType()->id())
      ->condition('t.entity_bundle', $entity->bundle())
      ->condition('t.entity_id', $entity->id());
    $entity_type = $entity->getEntityType();
    if ($entity_type->isRevisionable()) {
      $select_query->condition('t.entity_version_id', $entity->getRevisionId());
    }
    $query_results = $select_query->execute();
    while ($result = $query_results->fetchObject()) {
      $storage = $this->entityTypeManager->getStorage($result->referenced_entity_type);
      $referenced_entity_type = $this->entityTypeManager->getDefinition($result->referenced_entity_type);
      $key = $referenced_entity_type->isRevisionable() ? $result->referenced_entity_version_id : 0;
      $return[$result->referenced_entity_id]['_entity_type'] = $referenced_entity_type;
      $return[$result->referenced_entity_id]['items'][$key][$result->field_delta] = $storage->load($result->referenced_entity_id);
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function entitySave(ContentEntityInterface $entity) {
    // @todo: Investigate and review for performance and separation of ops.
    $entityref_field_names = $this->getEntityReferenceFields($entity);
    if (empty($entityref_field_names)) {
      $this->deleteByEntityType($entity->getEntityType(), $entity->bundle());
      return;
    }

    foreach ($entityref_field_names as $field_name) {
      $existing_stored_items = $this->getExistingStoredReferencesByField($entity, $field_name);
      $item_list = $entity->get($field_name);
      if (empty($item_list) || $item_list->isEmpty()) {
        if (!empty($existing_stored_items)) {
          $this->deleteByEntityField($entity, $field_name);
        }
      }

      $stored_items = [];
      foreach ($item_list as $delta => $item) {
        /** @var \Drupal\Core\Entity\ContentEntityInterface $referenced_entity */
        $referenced_entity = isset($item->entity) ? $item->entity : NULL;
        if (empty($referenced_entity)) {
          continue;
        }

        // @todo: Was this the missing piece? Evaluate performance later...
        // This will result in an initial set of performance related concerns
        // but will be addressed after, assuming this was the "missing" piece
        // in ensuring trees are correctly built. It is known that the tree is
        // not accurately updated during migrations and the attempt to use
        // items that are not yet within the tree may have been causing issues.
        $referenced_entity->save();

        $stored_items[$referenced_entity->id()] = [
          '_entity_type' => $referenced_entity->getEntityType(),
          'items' => [],
        ];

        $referenced_entity_version_key = $referenced_entity->getEntityType()->isRevisionable() ? $referenced_entity->getRevisionId() : 0;
        if (!isset($existing_stored_items[$referenced_entity->id()]['items'][$referenced_entity_version_key])) {
          $insert_fields = [
            'field_name' => $field_name,
            'field_delta' => $delta,
            'entity_type' => $entity->getEntityType()->id(),
            'entity_bundle' => $entity->bundle(),
            'entity_id' => $entity->id(),
            'entity_version_id' => $entity->getRevisionId(),
            'referenced_entity_type' => $referenced_entity->getEntityType()->id(),
            'referenced_entity_bundle' => $referenced_entity->bundle(),
            'referenced_entity_id' => $referenced_entity->id(),
          ];

          if ($referenced_entity->getEntityType()->isRevisionable()) {
            $insert_fields['referenced_entity_version_id'] = $referenced_entity->getRevisionId();
          }

          $this->database->insert('entity_reference_tree')
            ->fields($insert_fields)
            ->execute();
          $stored_items[$referenced_entity->id()]['items'][$referenced_entity_version_key][$delta] = TRUE;
        }
        else {
          if (!isset($existing_stored_items[$referenced_entity->id()]['items'][$referenced_entity_version_key][$delta])) {
            $query = $this->database->update('entity_reference_tree')
              ->fields([
                'field_delta' => $delta,
              ])
              ->condition('entity_type', $entity->getEntityType()->id())
              ->condition('entity_bundle', $entity->bundle())
              ->condition('entity_id', $entity->id())
              ->condition('entity_version_id', $entity->getRevisionId())
              ->condition('referenced_entity_type', $referenced_entity->getEntityType()->id())
              ->condition('referenced_entity_bundle', $referenced_entity->bundle())
              ->condition('referenced_entity_id', $referenced_entity->id());
            if ($referenced_entity->getEntityType()->isRevisionable()) {
              $query->condition('referenced_entity_version_id', $referenced_entity->getRevisionId());
            }
            $query->execute();
            $stored_items[$referenced_entity->id()]['items'][$referenced_entity_version_key][$delta] = TRUE;
          }
          elseif (isset($existing_stored_items[$referenced_entity->id()]['items'][$referenced_entity_version_key][$delta])) {
            $stored_items[$referenced_entity->id()]['items'][$referenced_entity_version_key][$delta] = TRUE;
          }
        }
      }

      $this->cleanExistingStoredVsNewlyStored($entity, $field_name, $existing_stored_items, $stored_items);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function entityDelete(ContentEntityInterface $entity) {
    $query = $this->database->delete('entity_reference_tree');
    $query->condition('referenced_entity_type', $entity->getEntityType()->id())
      ->condition('referenced_entity_bundle', $entity->bundle())
      ->condition('referenced_entity_id', $entity->id())
      ->execute();
    $query = $this->database->delete('entity_reference_tree');
    $query->condition('entity_type', $entity->getEntityType()->id())
      ->condition('entity_bundle', $entity->bundle())
      ->condition('entity_id', $entity->id())
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByEntityField(ContentEntityInterface $entity, $field_name) {
    $this->database->delete('entity_reference_tree')
      ->condition('field_name', $field_name)
      ->condition('entity_type', $entity->getEntityType()->id())
      ->condition('entity_bundle', $entity->bundle())
      ->condition('entity_id', $entity->id())
      ->condition('entity_version_id', $entity->getRevisionId())
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByEntityType(EntityTypeInterface $entity_type, $bundle = NULL) {
    $query = $this->database->delete('entity_reference_tree');
    $query->condition('entity_type', $entity_type->id());
    if (!empty($bundle)) {
      $query->condition('entity_bundle', $bundle);
    }
    $query->execute();
  }

  /**
   * Returns an array of entity reference field types for the entity's type.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to retrieve entity reference fields for.
   *
   * @return array
   *   An array of field names.
   */
  protected function getEntityReferenceFields(ContentEntityInterface $entity) {
    if (isset($this->entityReferenceFieldMap[$entity->getEntityType()->id()][$entity->bundle()])) {
      return $this->entityReferenceFieldMap[$entity->getEntityType()->id()][$entity->bundle()];
    }

    $this->entityReferenceFieldMap[$entity->getEntityType()->id()][$entity->bundle()] = [];
    $attached_fields = $this->entityFieldManager->getFieldDefinitions($entity->getEntityType()->id(), $entity->bundle());
    $entity_reference_field_types = ['entity_reference'];

    $entity_reference_base_classes = [
      EntityReferenceItem::class,
      EntityReferenceFieldItemList::class,
    ];

    foreach ($attached_fields as $field_name => $field_definition) {
      $field_class = $field_definition->getClass();
      $field_class = ltrim($field_class, '\\');
      $reflection = new \ReflectionClass($field_class);
      foreach ($entity_reference_base_classes as $entity_reference_class) {
        if (($field_class == $entity_reference_class) || ($reflection->isSubclassOf($entity_reference_class))) {
          $entity_reference_field_types[] = $field_definition->getType();
        }
      }
    }

    $entity_reference_field_map = [];
    foreach ($entity_reference_field_types as $field_type) {
      $entity_reference_field_map = array_merge($entity_reference_field_map, $this->entityFieldManager->getFieldMapByFieldType($field_type));
    }

    $entity_reference_fields_attached = [];
    foreach ($entity_reference_field_map as $entity_type => $field_map) {
      $_entity_reference_fields_attached = array_intersect_key($attached_fields, $field_map);
      $entity_reference_fields_attached = array_merge($entity_reference_fields_attached, $_entity_reference_fields_attached);
    }

    $entity_reference_fields_attached = array_diff_key($entity_reference_fields_attached, $this->entityFieldManager->getBaseFieldDefinitions($entity->getEntityType()
      ->id()));

    if (!empty($entity_reference_fields_attached)) {
      foreach ($entity_reference_fields_attached as $key => $entity_reference) {
        $settings = $entity_reference_fields_attached[$key]->getItemDefinition()
          ->getSettings();
        $target_type = $settings['target_type'];
        $entity_type = $this->entityTypeManager->getStorage($target_type)
          ->getEntityType();
        if ($entity_type instanceof ConfigEntityTypeInterface) {
          unset($entity_reference_fields_attached[$key]);
        }
      }

      $this->entityReferenceFieldMap[$entity->getEntityType()->id()][$entity->bundle()] = array_keys($entity_reference_fields_attached);
    }

    return $this->entityReferenceFieldMap[$entity->getEntityType()->id()][$entity->bundle()];
  }

  /**
   * Returns the stored entity reference tree record's ID if it exists.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The parent entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface $referenced
   *   The referenced entity.
   * @param string $field_name
   *   The field name in which the reference occurs.
   * @param int $delta
   *   The field delta.
   *
   * @return mixed
   *   Returns the ID of the record if it exists, otherwise FALSE.
   */
  protected function getExistingItem(ContentEntityInterface $entity, ContentEntityInterface $referenced, $field_name, $delta = 0) {
    $entity_type = $entity->getEntityType();
    $referenced_entity_type = $referenced->getEntityType();

    $query = $this->database->select('entity_reference_tree', 't');
    $query->fields('t', ['id']);
    $query->condition('t.field_name', $field_name)
      ->condition('t.field_delta', $delta)
      ->condition('t.entity_type', $entity->getEntityType()->id())
      ->condition('t.entity_bundle', $entity->bundle())
      ->condition('t.entity_id', $entity->id());
    if ($entity_type->isRevisionable()) {
      $query->condition('t.entity_version_id', $entity->getRevisionId());
    }

    $query->condition('t.referenced_entity_type', $referenced->getEntityType()
      ->id())
      ->condition('t.referenced_entity_bundle', $referenced->bundle())
      ->condition('t.referenced_entity_id', $referenced->id());
    if ($referenced_entity_type->isRevisionable()) {
      $query->condition('t.referenced_entity_version_id', $referenced->getRevisionId());
    }

    $query_results = $query->execute()->fetchAssoc();
    return !empty($query_results['id']) ? $query_results['id'] : FALSE;
  }

  /**
   * Returns the root entities for a specific entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to retrieve root entities for.
   *
   * @return array
   *   An array of root entities, keyed by the entity ID and grouped by entity
   *   type (e.g. node).
   */
  protected function buildRootEntities(ContentEntityInterface $entity) {
    $items = [];
    $this->buildRootEntitiesRecursively($entity, $items);
    return $items;
  }

  /**
   * Recursively checks for the absolute root entity within a tree.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to retrieve the root for.
   * @param array $items
   *   An array of root entities. Passed by reference.
   *
   * @return array
   *   The root entities.
   */
  protected function buildRootEntitiesRecursively(ContentEntityInterface $entity, array &$items) {
    $select_query = $this->database->select('entity_reference_tree', 't');
    $select_fields = [
      'id',
      'entity_type',
      'entity_bundle',
      'entity_id',
      'entity_version_id',
    ];
    $select_query->fields('t', $select_fields)
      ->condition('referenced_entity_type', $entity->getEntityType()->id())
      ->condition('referenced_entity_bundle', $entity->bundle())
      ->condition('referenced_entity_id', $entity->id());
    if ($entity->getEntityType()->isRevisionable()) {
      $select_query->condition('referenced_entity_version_id', $entity->getRevisionId());
    }
    $query_results = $select_query->execute();

    while ($result = $query_results->fetchObject()) {
      $storage = $this->entityTypeManager->getStorage($result->entity_type);
      if ($parent_entity = $storage->loadRevision($result->entity_version_id)) {
        $parents = $this->buildRootEntitiesRecursively($parent_entity, $items);
        if (empty($parents)) {
          $items[$parent_entity->getEntityType()->id()][$parent_entity->id()] = $parent_entity;
        }
      }
    }
    return $items;
  }

  /**
   * Cleans the {entity_reference_tree} table from existing vs. newly stored.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to clean items for.
   * @param string $field_name
   *   The field name to clean data for.
   * @param array $existing_stored_items
   *   An array of existing stored data.
   * @param array $stored_items
   *   An array of newly stored data.
   */
  protected function cleanExistingStoredVsNewlyStored(ContentEntityInterface $entity, $field_name, array $existing_stored_items, array $stored_items) {
    foreach ($existing_stored_items as $entity_id => $v) {
      if (empty($v['items'])) {
        continue;
      }

      foreach ($v['items'] as $version_id => $k) {
        foreach ($k as $delta_value => $i) {
          if (isset($stored_items[$entity_id]['items'][$version_id][$delta_value])) {
            continue;
          }

          $query = $this->database->delete('entity_reference_tree')
            ->condition('entity_type', $entity->getEntityType()->id())
            ->condition('entity_bundle', $entity->bundle())
            ->condition('entity_id', $entity->id());
          if ($entity->getEntityType()->isRevisionable()) {
            $query->condition('entity_version_id', $entity->getRevisionId());
          }

          $query->condition('field_name', $field_name)
            ->condition('field_delta', $delta_value)
            ->condition('referenced_entity_id', $entity_id);
          if (isset($v['_entity_type']) && $v['_entity_type']->isRevisionable()) {
            $query->condition('referenced_entity_version_id', $version_id);
          }
          $query->execute();
        }
      }
    }
  }

}
