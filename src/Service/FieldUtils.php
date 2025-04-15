<?php


namespace Drupal\yse_cascades\Service;

//I am going to get killed with interface vs non

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Url;
use Drupal\menu_entity_index\TrackerInterface;
use Drupal\menu_item_extras\Entity\MenuItemExtrasMenuLinkContent;
use Drupal\menu_item_extras\Service\MenuLinkTreeHandlerInterface;
use Drupal\menu_item_extras\Utility\Utility as ExtraUtils;
use Drupal\menu_link_content\Entity\MenuLinkContent as MenuLinkContentBase;
use Drupal\yse_cascades\Service\NodeFormUtils;
use Drupal\yse_cascades\Service\TreeUtils;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;


class FieldUtils {

  use DependencySerializationTrait;


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
   * The entity definition update manager.
   *
   * @var \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface
   */
  protected $entityDefinitionUpdateManager;

  /**
   * The menu link manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $menuLinkManager;

  /**
   * The menu link tree handler.
   *
   * @var \Drupal\menu_item_extras\Service\MenuLinkTreeHandlerInterface
   */
  protected $menuLinkTreeHandler;

  /**
   * The menu entity index tracker.
   *
   * @var Drupal\menu_entity_index\TrackerInterface
   */
  protected $tracker;

  /**
   * The yse_cascades node form utils.
   *
   * @var \Drupal\yse_cascades\Service\NodeFormUtils
   */
  protected $nodeFormUtils;

  /**
   * The yse_cascades menu tree utils.
   *
   * @var \Drupal\yse_cascades\Service\TreeUtils
   */
  protected $treeUtils;

  /**
   * The field definition. ONLY IF THIS IS A VAR WE PASS AROUND
   * Not if we use the object class I guess.
   *
   * @var \Drupal\Core\Field\BaseFieldDefinition
   */
  protected $primaryFieldDefinition;

  /**
   * The current database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * NOT SURE IF THIS BECOMES A SHARED VAR..
   * It is passed in
   * The form state.
   *
   * @var \Drupal\Core\Form\FormStateInterface
   */
  //protected $formState;

  /**
   * FieldUtils constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *  Entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *  The entity field manager.
   * @param \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface $entity_definition_update_manager
   *  Entity definition update manager.
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager
   *  The menu link manager
   * @param \Drupal\menu_item_extras\Service\MenuLinkTreeHandlerInterface $menu_link_tree_handler
   *  The menu link tree handler
   * @param \Drupal\menu_entity_index\TrackerInterface $tracker
   *  The menu entity index tracker
   * @param \Drupal\yse_cascades\Service\NodeFormUtils $node_form_utils
   *  The yse_cascades node form utils
   * @param \Drupal\yse_cascades\Service\TreeUtils $tree_utils
   *  The yse_cascades menu tree utils
   * @param \Drupal\Core\Database\Connection $connection
   *   The current database connection.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, EntityDefinitionUpdateManagerInterface $entity_definition_update_manager, MenuLinkManagerInterface $menu_link_manager, MenuLinkTreeHandlerInterface $menu_link_tree_handler, TrackerInterface $tracker, NodeFormUtils $node_form_utils, TreeUtils $tree_utils, Connection $connection) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityDefinitionUpdateManager = $entity_definition_update_manager;
    $this->menuLinkManager = $menu_link_manager;
    $this->menuLinkTreeHandler = $menu_link_tree_handler;
    $this->tracker = $tracker;
    $this->nodeFormUtils = $node_form_utils;
    $this->treeUtils = $tree_utils;
    $this->connection = $connection;
  }

  /**
   * Get the FieldDefinition object required to render this field's formatter.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition
   *   The field definition.   *
   */
  public function getPrimaryFieldDefinition() {
    if (!isset($this->primaryFieldDefinition)) {
      $this->primaryFieldDefinition = BaseFieldDefinition::create('boolean')
        ->setLabel(t('Primary'))
        ->setName('primary')
        ->setTargetEntityTypeId('menu_link_content')
        ->setTargetBundle(NULL)
        ->setProvider('yse_cascades')
        ->setDescription(t('This exclusive flag governs which menu link item loads in a nodeform.'))
        ->setDisplayOptions('view', [
          'label' => 'hidden',
          'region' => 'hidden',
          'type' => 'boolean',
          'weight' => 0,
        ])
        ->setDisplayConfigurable('view', FALSE)
        ->setDisplayOptions('form', [
          'type' => 'boolean_checkbox',
          'settings' => [
            'display_label' => TRUE,
          ],
          'weight' => 3,
        ])
        ->setCardinality(1)
        ->setDisplayConfigurable('form', TRUE);
    }
    return $this->primaryFieldDefinition;
  }


  // @see presave
  function installMenuLinkContentAddPrimaryField() {

    $entity_type = $this->entityTypeManager->getDefinition('menu_link_content');

    //see if we have this already
    $defs = $this->entityFieldManager->getFieldStorageDefinitions('menu_link_content');

    if (empty($defs['primary'])) {

      $definition = getPrimaryFieldDefinition();

      $this->entityDefinitionUpdateManager->installFieldStorageDefinition('primary', $entity_type->id(), 'menu_link_content', $definition);
      $stored_def = $this->entityDefinitionUpdateManager->getFieldStorageDefinition('primary', $entity_type->id());
      $this->entityDefinitionUpdateManager->updateFieldStorageDefinition($stored_def);
      $this->entityTypeManager->clearCachedDefinitions();
      $this->entityDefinitionUpdateManager->updateEntityType($entity_type);

    }
    //then call updb and trigger update
  }


  function updateMenuLinkContentAddPrimaryField() {

    //This sets all menu content items to be true from primary, presave
    //takes care of this for new items that do not have any other refs to the same node
    //NOTE:  this does mean if there are multiple links with the same node, all but one need to have primary 'off'
    //look for views to help with this
    $entity_type = $this->entityTypeManager->getDefinition('menu_link_content');
    $stored_def = $this->entityDefinitionUpdateManager->getFieldStorageDefinition('primary', $entity_type->id());

    if ($stored_def) {
      $menus = $this->entityTypeManager
        ->getStorage('menu')
        ->loadMultiple();

      if (!empty($menus)) {
        foreach ($menus as $menu_id => $menu) {
          $menu_links = $this->entityTypeManager
            ->getStorage('menu_link_content')
            ->loadByProperties(['menu_name' => $menu_id]);

          if (!empty($menu_links)) {
            foreach ($menu_links as $menu_link) {
              $menu_link->set('primary', 1);
              $menu_link->save();
            }
          }
        }
      }
    }
  }


  function get_primary_entity_base_field_info($entity_type) {
    // every hook is being loaded with Drupal\Core\Entity\ContentEntityType
    // I have no idea why

    $c = get_class($entity_type);
    $i = $entity_type->get('id');
    if ($i == 'menu_link_content') {
      if ($c == 'Drupal\Core\Entity\ContentEntityType') {
        //will drupal notice?
        $entity_type = $this->entityTypeManager->getDefinition($i);
        $fields = [];
        $fields['primary'] = getPrimaryFieldDefinition();
      }
      return $fields;
    }
  }


  /**
   * Implements hook_entity_extra_field_info().
   * Puts our source counter in the form config pages
   */
  function get_entity_extra_field_info() {
    $extra = [];
    //ROCKING WITH THE MENU ENTITY INDEX AS A GUIDE
    foreach ($this->tracker->getTrackedEntityTypes() as $entity_type_id) {
      $subtype_entity_type = $this->entityTypeManager->getDefinition($entity_type_id)->getBundleEntityType();
      if (!$subtype_entity_type) {
        $extra[$entity_type_id][$entity_type_id]['form']['cascades_for_node'] = [
          'label' => t('Inheritance Node List'),
          'description' => t('Lists nodes that this node can get values from.'),
          'visible' => FALSE,
        ];
        continue;
      }
      $subtypes = $this->entityTypeManager->getStorage($subtype_entity_type)->loadMultiple();
      foreach ($subtypes as $machine_name => $subtype) {
        $extra[$entity_type_id][$subtype->id()]['form']['cascades_for_node'] = [
          'label' => t('Inheritance Node List'),
          'description' => t('Lists nodes that this node can get values from.'),
          'visible' => FALSE,
        ];
      }
    }
    return $extra;
  }




  //this had a live ref to edit form in place


  /**
   * Implements hook_node_form_alter().
   */
  function node_form_alter(&$form, FormStateInterface $form_state, $form_id) {
    $form_object = $form_state->getFormObject();

    if (!$form_object || !$form_object instanceof ContentEntityForm) {
      // We only care about entity forms.
      return;
    }
    elseif ($form_object->getOperation() != 'edit') {
      // New entities are always unreferenced.
      return;
    }
    $entity = $form_state->getFormObject()->getEntity();

    if (!in_array($entity->getEntityTypeId(), $this->tracker->getTrackedEntityTypes())) {
      // Tracking is not enabled for this entity type.
      return;
    }

    // Add reference tracker pseudo-field.
    // THIS NEEDS TO BE IN SERVICES
    $data = self::_getMenuEntityIndexExtras($entity);

    $form['menu_entity_index'] = [
      '#type' => 'details',
      '#title' => \Drupal::translation()->formatPlural(
        count($data),
        'Referenced by 1 menu link',
        'Referenced by @count menu links'
      ),
      '#open' => FALSE,
      '#access' => \Drupal::currentUser()->hasPermission('view menu_entity_index form field'),
    ];

    $xtra = \Drupal::service('module_handler')->moduleExists('menu_item_extras') ? TRUE : FALSE;

    $form['menu_entity_index']['table'] = [
      '#type' => 'table',
      '#header' => [
        t('Menu'),
        t('Label'),
        t('Level'),
      ],
      '#empty' => t('- None -'),
    ];

    if ($xtra) {
      //array_push($form['menu_entity_index']['table']['#header'], t('Primary'), t('Home'), t('Source'));
      array_push($form['menu_entity_index']['table']['#header'], t('Primary'));
    }


    foreach ($data as $row) {
      $assoc = [
        'menu' => [
          '#markup' => $row['menu_name'],
        ],
        'label' => empty($row['link']) ? [
          '#markup' => $row['label'],
        ] : [
          '#type' => 'link',
          '#title' => $row['label'],
          '#url' => $row['link'],
        ],
        'level' => [
          '#markup' => $row['level'],
        ],
      ];

      if ($xtra) {
        $assoc += [
          'primary' => [
            '#markup' => $row['primary'],
          ]
        ];
      }

      $form['menu_entity_index']['table'][] = $assoc;
    }



    if ($xtra) {

      $donors = self::_getCascadeNodesExtras($entity);
      if (!empty($donors)) {

        $form['cascades_for_node'] = [
          '#type' => 'details',
          '#title' => \Drupal::translation()->formatPlural(
            count($donors),
            'May get values from 1 token set',
            'May get values from @count tokens'
          ),
          '#open' => FALSE,
          '#access' => \Drupal::currentUser()->hasPermission('administer nodes'),
          '#description' => t('Please refer to the <a href="@help" target="_blank">help page</a> for more details on token use.', ['@help' => Url::fromRoute('help.page', ['name' => 'yse_cascades'])->toString()]),

        ];

        $form['cascades_for_node']['table'] = [
          '#type' => 'table',
          '#header' => [
            t('Base'),
            t('Type'),
            t('Result'),

          ],
          '#empty' => t('- None -'),
        ];

        foreach ($donors as $row) {
          $assoc = [
            'base' => [
              '#markup' => $row['token_base'],
            ],
            'type' => [
              '#markup' => $row['technique'],
            ],
            'result' => [
              '#markup' => $row['resultmarkup'],
            ],

          ];

          $form['cascades_for_node']['table'][] = $assoc;
        }
      }
    }
  }

  function _getMenuEntityIndexExtras(EntityInterface $entity) {
    $data = [];
    $type = $entity->getEntityTypeId();
    if (in_array($entity->getEntityTypeId(), $this->tracker->getTrackedEntityTypes())) {
      $id = $entity->id();
      $result = $this->connection->select('menu_entity_index')
        ->fields('menu_entity_index', [
          'entity_type',
          'entity_id',
          'entity_uuid',
          'menu_name',
          'level',
          'langcode',
        ])
        ->condition('target_type', $type)
        ->condition('target_id', $id)
        ->orderBy('menu_name', 'ASC')
        ->orderBy('level', 'ASC')
        ->execute();
      $menus = [];
      foreach ($result as $row) {
        if (!isset($menus[$row->menu_name])) {
          $entity = $this->entityTypeManager->getStorage('menu')->load($row->menu_name);
          $menus[$row->menu_name] = $entity->label();
        }

        $entity = $this->entityTypeManager->getStorage($row->entity_type)->load($row->entity_id);
        if ($entity instanceof TranslatableInterface && $entity->hasTranslation($row->langcode)) {
          $entity = $entity->getTranslation($row->langcode);
        }
        if ($entity) {
          $rowdata = [
            'menu_name' => $menus[$row->menu_name],
            'level' => $row->level,
            'label' => $entity->getTitle(),
            'link' => $entity->access('view') ? $entity->toUrl() : '',
            'language' => $entity->language()->getName(),
          ];
          if (\Drupal::service('module_handler')->moduleExists('menu_item_extras')) {
            $plugin = 'menu_link_content:' . $row->entity_uuid;
            $menu_link_content = $this->menuLinkManager->createInstance($plugin);
            // lets see if this menu link has extras
            $menu_link_xentity = $this->menuLinkTreeHandler->getMenuLinkItemEntity($menu_link_content);
            if (!empty($menu_link_xentity)) {
              $menu_link_primary = $menu_link_xentity->hasField('primary') ? $menu_link_xentity->get('primary')->value : FALSE;
            }

            $rowdata += [
              'primary' => $menu_link_primary,
            ];

          }
          $data[] = $rowdata;
        }
      }
    }
    return $data;
  }

  function _getCascadeNodesExtras(EntityInterface $entity) {

    $entity_id = $entity->id();
    $node_type = $entity->type->entity;
    //returns machine names for menus available to this node
    //and therefore could be attached to the nodeform
    $type_menus = $node_type->getThirdPartySetting('menu_ui', 'available_menus', ['main']);
    if (empty(array_values($type_menus))) {
      return;
    }
    //This gives us one again, not an array. Should check for extras first...
    $menu_link_info = $this->nodeFormUtils->get_menu_link_defaults($entity);
    if (!empty($menu_link_info['entity_id'])) {
      $menu_link = MenuLinkContentBase::load($menu_link_info['entity_id']);
      $is_extra = ExtraUtils::checkBundleHasExtraFieldsThanEntity('menu_link_content', $menu_link->getMenuName());
      if ($is_extra) {
        $menu_link = MenuItemExtrasMenuLinkContent::load($menu_link_info['entity_id']);
      }
    }
    else {
      return;
    }

    $plugin_id = $menu_link->getPluginId();
    if (!empty($plugin_id)) {
      $menusteps = $this->menuLinkManager->getParentIds($plugin_id);
      $harvest_results = $this->treeUtils->harvest_menu_link_content_extras($plugin_id, $menusteps);
      $extrarows = [];
      if (!empty($harvest_results)) {
        foreach ($harvest_results as $fieldkey => $result) {
          $rowdata = $resultmarkup = [];
          //TODO config this array
          foreach (['catalog', 'collect', 'levelup', 'feature'] as $technique) {
            if (str_starts_with($fieldkey, "field_{$technique}")) {
              if (!empty($result['source_nodes']) && is_array($result['source_nodes']) && count($result['source_nodes']) > 0) {
                $kv = array_combine($result['source_nodes'], $result['value_string']);
                foreach ($kv as $k => $v) {
                  $resultmarkup[] = "<a href='/node/{$k}' target='_xtra' class='xtramarkup'>{$v}</a>";
                }
                $rowdata = [
                  'token_base' => ($technique == 'levelup') ? "cascades:{$result['field_name']}:levelup" : "cascades:{$result['field_name']}",
                  'field_name' => $result['field_name'],
                  'technique' => $result['technique'],
                  'resultmarkup' => implode(', ', $resultmarkup),
                ];
              }
              if (!empty($rowdata['field_name'])) {
                $extrarows[] = $rowdata;
              }
            }
          }
        }
        return $extrarows;
      }
    }
    else {
      return;
    }
  }

}
