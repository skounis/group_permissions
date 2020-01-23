<?php

namespace Drupal\group_permissions;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group_permissions\Entity\GroupPermission;

/**
 * Request entity extractor class.
 */
class GroupPermissionsManager {

  /**
   * The array of the group custom permissions.
   *
   * @var array
   */
  protected $customPermissions = [];

  /**
   * The array of the group permissions objects.
   *
   * @var array
   */
  protected $groupPermissions = [];

  /**
   * The array of the outsider group roles.
   *
   * @var array
   */
  protected $outsiderRoles = [];

  /**
   * The cache backend to use.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Handles custom permissions.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   */
  public function __construct(CacheBackendInterface $cache_backend, EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cacheBackend = $cache_backend;
  }

  /**
   * Set group permission.
   *
   * @param \Drupal\group_permissions\Entity\GroupPermission $group_permission
   *   Group permission.
   */
  public function setCustomPermission(GroupPermission $group_permission) {
    $this->customPermissions[$group_permission->getGroup()->id()] = $group_permission;
  }

  /**
   * Helper function to get custom group permissions.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return array
   *   Permissions array.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function getCustomPermissions(GroupInterface $group) {
    $custom_permissions = [];
    $group_id = $group->id();
    if (empty($this->customPermissions[$group_id])) {
      $cid = "custom_group_permissions:$group_id";
      $data_cached = $this->cacheBackend->get($cid);
      if (!$data_cached) {
        /** @var \Drupal\group_permissions\Entity\GroupPermission $group_permission */
        $group_permission = GroupPermission::loadByGroup($group);
        $tags = [];
        if ($group_permission) {
          $this->groupPermissions[$group_id] = $group_permission;
          $tags[] = "group:$group_id";
          $tags[] = "group_permission:{$group_permission->id()}";
          $custom_permissions = $group_permission->getPermissions()
            ->first()
            ->getValue();
        }

        // Store the tree into the cache.
        $this->cacheBackend->set($cid, $custom_permissions, CacheBackendInterface::CACHE_PERMANENT, $tags);
      }
      else {
        $custom_permissions = $data_cached->data;
      }

      $this->customPermissions[$group_id] = $custom_permissions;
    }
    else {
      $custom_permissions = $this->customPermissions[$group_id];
    }

    return $custom_permissions;
  }

  /**
   * Get group permission object.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return \Drupal\group_permissions\Entity\GroupPermission|null
   *   Group permission.
   */
  public function getGroupPermission(GroupInterface $group) {
    $group_id = $group->id();
    if (!empty($this->groupPermissions[$group_id])) {
      $this->groupPermissions[$group_id] = GroupPermission::loadByGroup($group);
    }

    return $this->groupPermissions[$group_id];
  }

  /**
   * Checks custom for collection.
   *
   * @param string $permission
   *   Permission.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current user.
   *
   * @return bool
   *   Result of the check.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function hasPermission($permission, GroupInterface $group, AccountInterface $account = NULL) {
    if (!empty($account) && $account->hasPermission('bypass group access')) {
      return TRUE;
    }

    if ($account->isAnonymous()) {
      return $this->checkAnonymousRole($permission, $group);
    }
    elseif ($group->getMember($account)) {
      return $this->checkGroupRoles($permission, $group, $account);
    }
    else {
      return $this->checkOutsiderRoles($permission, $group);
    }
  }

  /**
   * Checks anonymous role.
   *
   * @param string $permission
   *   Permission.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return bool
   *   Result of check.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function checkAnonymousRole($permission, GroupInterface $group) {
    $result = FALSE;
    $custom_permissions = $this->getCustomPermissions($group);
    if (!empty($custom_permissions)) {
      $role_id = $group->getGroupType()->getAnonymousRoleId();
      if (!empty($custom_permissions[$role_id]) && in_array($permission, $custom_permissions[$role_id])) {
        return TRUE;
      }
    }

    return $result;
  }

  /**
   * Checks outsider roles.
   *
   * @param string $permission
   *   Permission.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return bool
   *   Result of check.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function checkOutsiderRoles($permission, GroupInterface $group) {
    $result = FALSE;
    $custom_permissions = $this->getCustomPermissions($group);
    if (!empty($custom_permissions)) {
      $outsider_roles = $this->getOutsiderRoles($group);
      return $this->checkRoles($permission, $custom_permissions, $outsider_roles);
    }

    return $result;
  }

  /**
   * Checks roles for permissions.
   *
   * @param string $permission
   *   Permission.
   * @param array $custom_permissions
   *   Custom permissions.
   * @param array $roles
   *   Roles list.
   *
   * @return bool
   *   Role has the permission or not.
   */
  protected function checkRoles($permission, array $custom_permissions = [], array $roles = []) {
    foreach ($roles as $role_name => $role) {
      if (!empty($custom_permissions[$role_name]) && in_array($permission, $custom_permissions[$role_name])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Get outsider roles.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   *
   * @return mixed
   *   List of outsider roles.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getOutsiderRoles(GroupInterface $group) {
    $group_type = $group->getGroupType();
    $group_type_id = $group_type->id();
    if (empty($this->outsiderRoles[$group_type_id])) {
      $storage = $this->entityTypeManager->getStorage('group_role');
      $outsider_roles = $storage->loadSynchronizedByGroupTypes([$group_type_id]);
      $outsider_roles[$group_type->getOutsiderRoleId()] = $group_type->getOutsiderRole();

      $this->outsiderRoles[$group_type_id] = $outsider_roles;
    }

    return $this->outsiderRoles[$group_type_id];
  }

  /**
   * Check normal user roles in the group.
   *
   * @param string $permission
   *   Permission.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User.
   *
   * @return bool
   *   Permission check.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function checkGroupRoles($permission, GroupInterface $group, AccountInterface $account) {
    $result = FALSE;
    $custom_permissions = $this->getCustomPermissions($group);
    if (!empty($custom_permissions)) {
      $member = $group->getMember($account);
      if (!empty($member)) {
        return $this->checkRoles($permission, $custom_permissions, $member->getRoles());
      }
    }

    return $result;
  }

  /**
   * Get all group permissions objects.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Group permissions list.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getAll() {
    return $this->entityTypeManager->getStorage('group_permission')->loadMultiple();
  }

}