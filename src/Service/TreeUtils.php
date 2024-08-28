<?php

namespace Drupal\yse_cascades\Service;


use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\menu_item_extras\Entity\MenuItemExtrasMenuLinkContent;
use Drupal\menu_item_extras\Service\MenuLinkTreeHandlerInterface;
use Drupal\menu_link_content\Entity\MenuLinkContent as MenuLinkContentBase;
use Drupal\node\Entity\Node as NodeLoader;

class TreeUtils {

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
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager
   *  The menu link manager
   * @param \Drupal\menu_item_extras\Service\MenuLinkTreeHandlerInterface $menu_link_tree_handler
   *  The menu link tree handler
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, MenuLinkManagerInterface $menu_link_manager, MenuLinkTreeHandlerInterface $menu_link_tree_handler) {
    $this->entityTypeManager = $entity_type_manager; //is this better than NodeInterface::load()?
    $this->entityFieldManager = $entity_field_manager;
    $this->menuLinkManager = $menu_link_manager;
    $this->menuLinkTreeHandler = $menu_link_tree_handler;
  }

  /**
   * Gathering could end up looking like
   * $gathering = [
   *  'field_catalog_one' => [
   *    'technique' => 'catalog',
   *    'field_name' => 'field_catalog_one',
   *    'value_string = '23|24|25',
   *    'value_object  = [23,24,25]
   *  ],
   *  'field_catalog_abc' => [
   *    'technique' => 'catalog',
   *    'field_name' => 'field_catalog_abc',
   *    'value_string = '13|24|3003',
   *    'value_object  = [13,24,3003]
   *  ],
   *  'field_feature_thing' => [
   *    'technique' => 'catalog',
   *    'field_name' => 'field_feature_thing',
   *    'value_string = 'Super thing like a para',
   *    'value_object  = [markup ? "Super thing like a para"]
   *  ],
   *  'field_collect_abc' => [
   *    'technique' => 'collect',
   *    'field_name' => 'field_collect_abc',
   *    'value_string = 'tom|disk|harry|subarrays|get|flattened',
   *    'value_objext  = ['tom','disk','harry','subarrays','get','flattened']
   *  ],
   *  field_landing_homenode' => [
   *    'technique' => 'landing',
   *    'field_name' => 'field_landing_homenode',
   *    'value_string = '1003',
   *    'value_object = [Node]
   *  ],
   *  'field_onestop_homenode' => [
   *    'technique' => 'onestop',
   *    'field_name' => 'field_landing_homenode',
   *    'value_string = '1003',
   *  ]
   * ]
   *s
   */
  function harvest_menu_link_content_extras($plugin_id, $ancestors = NULL, $fieldname = NULL) {
    $gathering = NULL;
    $startlink = $this->menuLinkManager->createInstance($plugin_id);
    if (!empty($startlink)) {
      $mlientity = $this->menuLinkTreeHandler->getMenuLinkItemEntity($startlink);
      $ancestors = $ancestors ?? $this->menuLinkManager->getParentIds($plugin_id);
      $anchornid = $startlink->getRouteParameters()['node'];

      foreach ($mlientity->getFieldDefinitions() as $field_name => $field_def) {
        //fields per bundle
        if (empty($fieldname) || ($fieldname == $field_name)) {
          if (!($field_def instanceof BaseFieldDefinition)) {
            //someday make this plugin and load available plugins
            //return first matching ancestor nid
            if (str_starts_with($field_name, 'field_onestop')) {
              $harvest = self::climb_and_gather($ancestors, 'onestop', $field_name);
              $gathering[$field_name] = $harvest;
            }
            //return matching ancestor nids
            if (str_starts_with($field_name, 'field_catalog')) {
              $harvest = self::climb_and_gather($ancestors, 'catalog', $field_name);
              $gathering[$field_name] = $harvest;
            }
            // return instance field value (type is variable)
            if (str_starts_with($field_name, 'field_feature')) {
              if (!empty($mlientity->get($field_name)->getString())) {
                $gathering[$field_name] = [
                  'technique' => 'feature',
                  'field_name' => $field_name,
                  'source_nodes' => [$anchornid],
                  'value_string' => [$mlientity->get($field_name)->getString()],
                  'value_object' => $mlientity->get($field_name)->getValue(), //(array)
                ];
              }
            }
            // return matching ancestor field values
            if (str_starts_with($field_name, 'field_collect')) {
              $harvest = self::climb_and_gather($ancestors, 'collect', $field_name);
              $gathering[$field_name] = $harvest;
            }
            // return first matching ancestor node enity
            if (str_starts_with($field_name, 'field_landing')) {
              $harvest = self::climb_and_gather($ancestors, 'landing', $field_name);
              $gathering[$field_name] = $harvest;
            }
            if ($field_name == 'field_path_shortcode') {
              $harvest = self::climb_and_gather($ancestors, 'swapout', $field_name);
              $gathering[$field_name] = $harvest;
            }
          }
        }
      }
    }
    return $gathering;
  }

  function climb_and_gather($plugin_ids, $technique = 'catalog', $field_name = NULL) {
    //maybe the services will be loaded above
    $joinchar = "|"; //if we getString on value list, we'll get commas in there.

    $result = [
      'technique' => $technique,
      'field_name' => $field_name,
      'source_nodes' => NULL,
      'value_string' => NULL,
      'value_object' => NULL,
    ];

    foreach ($plugin_ids as $plugin_id) {
      $menu_link = $this->menuLinkManager->createInstance($plugin_id);
      $menu_xtra = $this->menuLinkTreeHandler->getMenuLinkItemEntity($menu_link);
      $result_string = $result_value = $result_nid = $result_link = $result_node = NULL;
      //HOW SHOULD WE TREAT NULLS IN A COLLECT OR CATALOG?
      //NOW WE WILL EXCLUDE THEM SO THE ARRAY HAS NO GAPS
      //using php empty rather than isEmpty so boolean 0 is ignored in this scheme
      if ($menu_xtra->hasField($field_name)) {
        $result_string = $menu_xtra->get($field_name)->getString();
        $result_value = $menu_xtra->get($field_name)->getValue();
        $result_nid = $menu_link->getRouteParameters()['node'];
        $result_link = ($technique == 'landing') ? $menu_xtra : NULL;
        //$result_node = ($technique == 'landing') ? $this->entityTypeManager->getStorage('node')->load($result_nid) : NULL;
        //keep looping for collect and catalog, end loop for onestop and landing.
        //adding plugin_id bc token arrays are built from assoc inputs.

        if (empty($menu_xtra->get($field_name)->getString())) {
          //only scareups can proceed with empties bc swapout
          switch ($technique) {
            case 'swapout':
              $result['source_nodes'][$plugin_id] = $result_nid;
              $result['value_string'][$plugin_id] = $menu_xtra->getTitle();
              continue 2;
          }
        }
        else {
          switch ($technique) {
            case 'catalog':
              $result['source_nodes'][$plugin_id] = $result_nid;
              $result['value_string'][$plugin_id] = $menu_xtra->getTitle();
              $result['value_object'][$plugin_id] = $result_nid;
              continue 2;
            case 'onestop':
              $result['source_nodes'][$plugin_id] = $result_nid;
              $result['value_string'][$plugin_id] = $menu_xtra->getTitle();
              $result['value_object'][$plugin_id] = $result_nid;
              break;
            case 'collect':
              $result['source_nodes'][$plugin_id] = $result_nid;
              $result['value_string'][$plugin_id] = $result_string;
              $result['value_object'][$plugin_id] = $result_value;
              continue 2;
            case 'landing':
              $result['source_nodes'][$plugin_id] = $result_nid;
              $result['value_string'][$plugin_id] = $menu_xtra->getTitle();
              $result['value_object'][$plugin_id] = $result_link;
              break;
            case 'swapout':
              $result['source_nodes'][$plugin_id] = $result_nid;
              $result['value_string'][$plugin_id] = $result_string;
              continue 2;
          }
        }
      }
    }

    //package strings.  if we need to truly flatten all for commas then
    //return iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveArrayIterator($value)), FALSE);
    //$result[$field_name]['value_string'] = implode($joinchar, $result[ $field_name ]['value_string']);
    return $result;
  }

}
