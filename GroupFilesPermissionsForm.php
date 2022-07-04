<?php

namespace Drupal\mm_imce_groups_bridge\Entity\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\imce\ImcePluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * GroupFilesPermissionsForm.
 *
 * Provides form which configures permissions for each group role.
 */
class GroupFilesPermissionsForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route matcher service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $currentRouteMatch;

  /**
   * The cache tag invalidator service.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * Folder permissions.
   *
   * @var array
   */
  public $folderPermissions;

  /**
   * Folder permissions entity.
   *
   * @var array
   */
  public $folderPermissionsEntity;

  /**
   * Constructs a new AccessByEntityForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $current_route_match
   *   The core route match service.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   * @param \Drupal\imce\ImcePluginManager $plugin_manager_imce
   *   Plugin manager for Imce Plugins.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $current_route_match,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
    ImcePluginManager $plugin_manager_imce
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentRouteMatch = $current_route_match;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
    $this->pluginManagerImce = $plugin_manager_imce;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('cache_tags.invalidator'),
      $container->get('plugin.manager.imce.plugin')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'group_files_permissions_form';
  }

  /**
   * Gets the roles to display in this form.
   *
   * @return \Drupal\core\Entity\EntityInterface[]
   *   An array of role objects.
   */
  protected function getRoles() {
    $group = $this->currentRouteMatch->getParameter('group');
    $properties = [
      'group_type' => $group->bundle(),
      'permissions_ui' => TRUE,
    ];

    return $this->entityTypeManager
      ->getStorage('group_role')
      ->loadByProperties($properties);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $imce_profile = $this->entityTypeManager
      ->getStorage('imce_profile')
      ->load('group_member');

    $parameters = $this->currentRouteMatch->getRouteObject()->getOption('parameters');
    $entity_type_id = array_keys($parameters)[0];
    $entity = $this->getRequest()->attributes->get($entity_type_id);

    $form['header'] = [
      '#type' => 'item',
      '#markup' => $this->t('Below is the access restriction matrix for %title entity.
          To deny access, check off the roles that should not access this item. For example,
          to deny any anonymous user access, check off "Anonymous User". To allow access for a role,
          leave the box unchecked. If a user has multiple roles, they will be denied access if any
          of the roles they have are checked.',
        ['%title' => $entity->label()]),
    ];

    $role_names = [];
    foreach ($this->getRoles() as $role_name => $role) {
      if ($role->isMember()) {
        $role_names[$role_name] = $role->label();
      }
    }

    $folders = $imce_profile->getConf('folders', []);
    $perms = $this->permissionInfo();
    unset($perms['resize_images']);
    $this->loadGroupFilesSettings($entity->id());

    // Processing folder permissions for future use in #default_value.
    if (!empty($this->folderPermissionsEntity)) {
      foreach ($this->folderPermissionsEntity as $group_folder_permissions) {
        $test = $group_folder_permissions->get('group_folder_permissions')->getValue();
        $processed_permissions = [];
        foreach ($test as $permission_data_item) {
          $processed_permissions[$permission_data_item['folder']][$permission_data_item['role']] = json_decode($permission_data_item['permissions'], TRUE);
        }
      }
    }

    // Store $role_names for use when saving the data.
    $form['role_names'] = [
      '#type' => 'value',
      '#value' => $role_names,
    ];

    $form['permissions'] = [
      '#type' => 'table',
      '#header' => [$this->t('Roles')],
      '#id' => 'permissions',
      '#attributes' => ['class' => ['permissions', 'js-permissions']],
      '#sticky' => TRUE,
    ];
    foreach ($perms as $perm) {
      $form['permissions']['#header'][$perm->getUntranslatedString()] = [
        'data' => $perm,
        'class' => ['checkbox'],
      ];
    }
    $folders_paths = [];
    foreach ($folders as $key => $folder) {
      $path = $folder['path'];
      $folders_paths[] = $path;

      $form['folders'] = [
        '#type' => 'value',
        '#value' => $folders_paths,
      ];

      $form['permissions'][$path . '-' . $key] = [
        [
          '#wrapper_attributes' => [
            'colspan' => count($role_names) + 1,
            'class' => ['folder'],
            'id' => 'folder' . $path,
          ],
          '#markup' => "<b>Permissions for folder /$path</b>",
        ],
      ];
      foreach ($role_names as $rid => $name) {
        $form['permissions'][$key . '-' . $rid]['description'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="permission"><span class="title">{{ title }}</span></div>',
          '#context' => [
            'title' => $name,
          ],
        ];
        foreach ($perms as $perm => $title) {
          $form['permissions'][$key . '-' . $rid][$perm] = [
            '#type' => 'checkbox',
            '#wrapper_attributes' => [
              'class' => ['checkbox'],
            ],
            '#default_value' => $processed_permissions[$path][$rid][$perm] ?? 0,
          ];
        }
      }
    }

    $form['entity_id'] = [
      '#type' => 'hidden',
      '#default_value' => $entity->id(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save permissions'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $number_of_roles = count($form_state->getValue('role_names'));
    $group_id = $form_state->getValue('entity_id');
    $permissions = $form_state->getValue(['permissions']);
    ksort($permissions);
    $folders_paths = $form_state->getValue('folders');
    $folders_permissions = array_chunk($permissions, $number_of_roles, TRUE);
    $this->loadGroupFilesSettings($group_id);
    if (empty($this->folderPermissionsEntity)) {
      $this->createGroupFilesSettings($group_id, $folders_permissions, $folders_paths);
    }
    else {
      $this->updateGroupFilesSettings($this->folderPermissionsEntity, $folders_permissions, $folders_paths);
    }
  }

  /**
   * Returns folder permission definition.
   *
   * @return array
   *
   *   An array of id:label pairs.
   */
  public function permissionInfo() {
    if (!isset($this->folderPermissions)) {
      $this->folderPermissions = $this->pluginManagerImce->permissionInfo();
    }
    return $this->folderPermissions;
  }

  /**
   * Returns folder permissions entity.
   *
   * @param int $group_id
   *   Group ID.
   *
   * @return array|\Drupal\Core\Entity\EntityInterface|null
   *
   */
  public function loadGroupFilesSettings(int $group_id):array {
    $this->folderPermissionsEntity = $this->entityTypeManager
      ->getStorage('files_settings')
      ->loadByProperties([
        'gid' => $group_id,
      ]);
    return $this->folderPermissionsEntity;
  }

  /**
   * Saves folders permmissions entity.
   *
   * @param int $group_id
   *   Group ID.
   *
   * @param array $folders_permissions
   *
   *   Contains permissions for each role in folder.
   *
   * @param array $folders_paths
   *
   *   Contains available folders in group.
   */
  public function createGroupFilesSettings(int $group_id, array $folders_permissions, array $folders_paths):void {
    $this->folderPermissionsEntity = $this->entityTypeManager
      ->getStorage('files_settings')
      ->create([
        'gid' => $group_id,
      ]);
    foreach ($folders_paths as $folder_key => $folder_path) {
      foreach ($folders_permissions[$folder_key] as $role_name => $role) {
        // Clearing keys to define for which folder role was configured.
        $role_name = substr($role_name, 2);
        $role = json_encode($role);
        $this->folderPermissionsEntity->get('group_folder_permissions')->appendItem([
          'folder' => $folder_path,
          'role' => $role_name,
          'permissions' => $role,
        ]);
      }
    }
    $this->folderPermissionsEntity->save();
  }

  /**
   * @param array $entity
   *
   *   Stores group-files permission entity.
   *
   * @param array $folders_permissions
   *
   *   Contains permissions for each role in folder.
   *
   * @param array $folders_paths
   *
   *   Contains available folders in group.
   */
  public function updateGroupFilesSettings(array $entity, array $folders_permissions, array $folders_paths):void {
    $entity = array_values($entity)[0];
    // Clearing list item to avoid duplication of field items.
    $entity->get('group_folder_permissions')->setValue([]);
    $entity->save();
    foreach ($folders_paths as $folder_key => $folder_path) {
      foreach ($folders_permissions[$folder_key] as $role_name => $role) {
        // Clearing keys to define for which folder role was configured.
        $role_name = substr($role_name, 2);
        $role = json_encode($role);
        $entity->get('group_folder_permissions')->appendItem([
          'folder' => $folder_path,
          'role' => $role_name,
          'permissions' => $role,
        ]);
      }
    }
    $entity->save();
  }

}
