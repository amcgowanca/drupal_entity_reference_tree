<?php

namespace Drupal\entity_reference_tree;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Defines the Entity Reference Tree Manager object.
 */
interface EntityReferenceTreeManagerInterface {

  /**
   * Performs storage operations for when the $entity has been saved.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity which has been saved.
   */
  public function entitySave(ContentEntityInterface $entity);

  /**
   * Performs storage operations for when the $entity has been deleted.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity which has been deleted.
   */
  public function entityDelete(ContentEntityInterface $entity);

  /**
   * Deletes stored data by entity and field specification.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to delete data for.
   * @param string $field_name
   *   The name of the field to delete related too.
   */
  public function deleteByEntityField(ContentEntityInterface $entity, $field_name);

  /**
   * Deletes stored data by entity type.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type to delete data by.
   * @param string|null $bundle
   *   The name of the bundle to delete data by, if NULL, all stored data by
   *   related to the entity type will be deleted.
   */
  public function deleteByEntityType(EntityTypeInterface $entity_type, $bundle = NULL);

  /**
   * Determines if a given entity has a relationship, therefore a parent.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to check.
   * @param bool $return_parents
   *   A boolean indicating if the parent entity should be returned or a
   *   boolean value indicating it has a parent. Default value is FALSE.
   *
   * @return mixed
   *   Returns a boolean FALSE if the $entity has no relationship, therefore
   *   it does not have a parent entity. If the $return_parent parameter is
   *   TRUE, an array of entities referencing $entity will be returned,
   *   otherwise a boolean TRUE will be returned.
   */
  public function hasParentEntities(ContentEntityInterface $entity, $return_parents = FALSE);

  /**
   * Returns an array of entities that are ancestral roots.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to retrieve ancestors for.
   *
   * @return array
   *   An array of roots, keyed by the root entity version identifiers, grouped
   *   the entity identifier and entity type. For example, a Media entity with
   *   a single parent root entity as a node would return as:
   *   ['node' => [nid => [vid => object]]].
   */
  public function getRootEntities(ContentEntityInterface $entity);

  /**
   * Returns an array of data stored by field where $entity is referenced.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The referenced entity.
   * @param string $field_name
   *   The name of the field to retrieve data for.
   *
   * @return array
   *   An array of data.
   */
  public function getExistingStoredReferencesByField(ContentEntityInterface $entity, $field_name);

  /**
   * Determines and returns a boolean if $entity belongs to published content.
   *
   * It is assumed that 'publishable' content implements
   * EntityPublishedInterface. It is important to note that only the default
   * revision of the parent ancestral entities are tested for being published.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to test if it belongs to published content.
   *
   * @return bool
   *   Returns TRUE if $entity has a published ancestral entity otherwise FALSE.
   */
  public function belongsToPublishedContent(ContentEntityInterface $entity);

  /**
   * Returns an array of published ancestral content entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to retrieve published ancestral entities for.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   An array of entities.
   */
  public function getParentPublishedContent(ContentEntityInterface $entity);

  /**
   * Returns an array of all ancestral entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to retrieve parents and ancestral entities for.
   *
   * @return array
   *   An array of parents, keyed by the entity version identifiers, grouped
   *   the entity identifier and entity type. For example, a Media entity with
   *   a single parent root entity as a node would return as:
   *   ['node' => [nid => [vid => object]]].
   */
  public function getParentEntities(ContentEntityInterface $entity);

}
