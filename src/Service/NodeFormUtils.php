<?php

namespace Drupal\yse_cascades\Service;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityRepository;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuParentFormSelector;
use Drupal\Core\Menu\MenuParentFormSelectorInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\menu_item_extras\Entity\MenuItemExtrasMenuLinkContent;
use Drupal\menu_item_extras\Utility\Utility as ExtraUtils;
use Drupal\menu_link_content\Entity\MenuLinkContent as MenuLinkContentBase;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\system\Entity\Menu;
use Drupal\system\MenuInterface;

class NodeFormUtils {

  /**
   * The menu parent form selector.
   *
   * @var \Drupal\Core\Menu\MenuParentFormSelectorInterface
   */
  protected $menuParentFormSelector;

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
   * The private tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $privateTempStoreFactory;

  /**
   * The Date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;


  /**
   * FieldUtils constructor.
   *
   * @param \Drupal\Core\Menu\MenuParentFormSelectorInterface $menu_parent_form_selector
   *  Entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *  Entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *  Private tempstore factory.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $private_temp_store_factory
   *  The date formatter
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *  The entity repository
   * @param  \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   */
  public function __construct(MenuParentFormSelectorInterface $menu_parent_form_selector, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, PrivateTempStoreFactory $private_temp_store_factory, DateFormatter $date_formatter, EntityRepositoryInterface $entity_repository) {
    $this->menuParentFormSelector = $menu_parent_form_selector;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->privateTempStoreFactory = $private_temp_store_factory;
    $this->dateFormatter = $date_formatter;
    $this->entityRepository = $entity_repository;
  }

  public function edit_menu_extra_refresh(array &$form, FormStateInterface &$form_state) {
    //Triggered when we change menu parent in standard menu_link subform

    if (empty($form_state->getTriggeringElement())) {
      //is it necessary to run twice.. what is validating?
      return;
    }

    if (!empty($form_state->getUserInput()) && !empty($form_state->getUserInput()['form_id'])) {
      $form_id = $form_state->getUserInput()['form_id'];
    }
    else {
      //TDOD Exception.
    }
    //ajax should knows if a form got used or changed, by triggering element and values vs input
    //getValues() brings the id and entity_id with it and seems to capture User Input as well
    //
    $menudata = $form_state->getValues()['menu'] ?? $form_state->getUserInput()['menu'] ?? [];
    if (!empty($menudata['menu_parent'])) {
      $bundle = $entity_type = $mlid = NULL;
      $plugin = explode(':', $menudata['menu_parent']);
      if (count($plugin) === 3) {
        list($bundle, $entity_type, $mlid) = $plugin;
      }
      else {
        $bundle = $plugin[0];
        $entity_type = 'menu_link_content';
      }
      //is the new menu parent link extra?
      $is_extra = ExtraUtils::checkBundleHasExtraFieldsThanEntity($entity_type, $bundle);
      if ($is_extra) {
        // we might be coming back from a menu with no extras so check and wrap if needed
        self::_get_empty_extra_wrapper($form, $form_state);
        $stored_mlc = $stored_bun = $chosen_mlc = NULL;

        //We create a MenuItemExtrasMenuLinkContent object - it will carry field defintions
        //to merge with the form display settings, organized by bundle or menu
        //HARDCODED view mode of 'nodeform'
        $fresh_mlc = self::_create_menulinkcontent_instance($form_state, $bundle);
        $merge_display = EntityFormDisplay::load('menu_link_content.' . $bundle . '.nodeform');
        if ($merge_display && ($merge_display instanceof EntityFormDisplay || $merge_display instanceof EntityFormDisplayInterface)) {
          //Grab prior state for this menu bundle.  It is possible to skip around
          //when choosing a parent.  If we find the same bundle was selected into,
          //we can take the current form_state values into account.  If not, we need
          //to see if they are stored in the tempstore.
          $priorstate = self::_get_prior_state($form, $form_state);
          if (!empty($priorstate) && !empty($priorstate[$bundle])) {
            //check which data we want to use.
            $d = $priorstate['last_menu_chosen'] == $bundle ? $menudata : $priorstate[$bundle];

            foreach ($fresh_mlc->getFieldDefinitions() as $field_name => $field_def) {

              $ajax_value = $d['extra'][$field_name][0]['value']
                ?? $d['extra'][$field_name]['value']
                ?? $d[$field_name][0]['value']
                ?? $d[$field_name]['value']
                ?? NULL;

              if ($field_def instanceof BaseFieldDefinition) {
                $form_state->setValue($field_name, $ajax_value);
                $form_state->set(['menu', $field_name], $ajax_value);
              }
              elseif (str_starts_with($field_name, 'field_')) {
                $form_state->setValue($field_name, $ajax_value);
                $form_state->set(['menu', 'link', 'extra', $field_name], $ajax_value);
              }
            }
          }
          //TODO check tempstore and populate vals from choices if possible.
          //to accomplish this, we need to get from old mlc and get IF choices are valid...
          $merge_display->buildForm($fresh_mlc, $form['menu']['link']['extra'], $form_state);

          //$merge_components = $merge_display->getComponents();
          foreach (Element::children($form['menu']['link']['extra']) as $key) {
            if ($key != 'tempstore_key' && strpos($key, 'field_') !== 0 && strpos($key, 'view_mode') !== 0) {
              unset($form['menu']['link']['extra'][$key]);
            }
            else {
              //TODO see if processForm etc does what this function does.
              self::_get_fauxprocessed_fieldwidget($form, $form_state, $form_id, $key);
            }
          }
          $ts = self::_save_temp_state($form, $form_state, $bundle, $menudata);
          //return the object that will replace the form via ajax DOM replacement
          return $form['menu']['link']['extra'];
        }
      }
      else {
        //hmmm this becomes node bundle somewhere...
        self::_save_temp_state($form, $form_state, $bundle, $menudata);
      }
    }
    // if we haven't returned a healthy subform, create and/or empty wrapper
    self::_get_empty_extra_wrapper($form, $form_state);
    return $form['menu']['link']['extra'];
  }

  function form_node_form_alter(&$form, FormStateInterface $form_state, $form_id) {
    // DIRECT COPY FROM menu_ui.module WITH OUR DEFAULTS REPLACEMENT
    // Generate a list of possible parents (not including this link or descendants).
    // @todo This must be handled in a #process handler.

    if (\Drupal::request()->isXmlHttpRequest() && (empty($form_state->getTriggeringElement()))) {
      //this might be a FAIL but I might not have to do all this before trigger pass.
      return;
    }

    $node = $form_state->getFormObject()->getEntity();
    /** @var \Drupal\node\NodeTypeInterface $node_type */
    $node_type = $node->type->entity;

    if (\Drupal::request()->isXmlHttpRequest() !== TRUE) {
      $marker = 'no_ajax';
    }
    //maybe this is the render phase.
    elseif (!empty($form_state->getTriggeringElement())) {
      $marker = 'ajax_w_trigger';
    }
    else {
      //maybe this is the validate phase.
      $marker = 'ajax_pre_trigger';
    }

    //find out which menus a node in this bundle can access
    //this can't be empty unless a bundle posts an empty array under available_menus
    $type_menus_ids = $node_type->getThirdPartySetting('menu_ui', 'available_menus', ['main']);
    if (empty($type_menus_ids)) {
      return;
    }


    $defaults = self::get_menu_link_defaults($node);

    /** @var \Drupal\system\MenuInterface[] $type_menus */
    //$type_menus = MenuInterface::loadMultiple($type_menus_ids);
    $type_menus = $this->entityTypeManager->getStorage('menu')->loadMultiple($type_menus_ids);
    $available_menus = [];
    foreach ($type_menus as $menu) {
      $available_menus[$menu->id()] = $menu->label();
    }
    if ($defaults['id']) {
      $default = $defaults['menu_name'] . ':' . $defaults['parent'];
    }
    else {
      $default = $node_type->getThirdPartySetting('menu_ui', 'parent', 'main:');
    }
    $parent_element = $this->menuParentFormSelector->parentSelectElement($default, $defaults['id'], $available_menus);
    // If no possible parent menu items were found, there is nothing to display.
    if (empty($parent_element)) {
      return;
    }

    $form['menu'] = [
      '#type' => 'details',
      '#title' => t('Menu settings'),
      '#access' => \Drupal::currentUser()->hasPermission('administer menu'),
      '#open' => (bool) $defaults['id'],
      '#group' => 'advanced',
      '#attached' => [
        'library' => ['menu_ui/drupal.menu_ui'],
      ],
      '#tree' => TRUE,
      '#weight' => -2,
      '#attributes' => ['class' => ['menu-link-form']],
    ];
    $form['menu']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => t('Provide a menu link'),
      '#default_value' => (int) (bool) $defaults['id'],
    ];
    $form['menu']['link'] = [
      '#type' => 'container',
      '#parents' => ['menu'],
      '#states' => [
        'invisible' => [
          'input[name="menu[enabled]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    // Populate the element with the link data.
    foreach (['id', 'entity_id'] as $key) {
      $form['menu']['link'][$key] = ['#type' => 'value', '#value' => $defaults[$key]];
    }

    $form['menu']['link']['title'] = [
      '#type' => 'textfield',
      '#title' => t('Menu link title'),
      '#default_value' => $defaults['title'],
      '#maxlength' => $defaults['title_max_length'],
    ];

    $form['menu']['link']['description'] = [
      '#type' => 'textfield',
      '#title' => t('Description'),
      '#default_value' => $defaults['description'],
      '#description' => t('Shown when hovering over the menu link.'),
      '#maxlength' => $defaults['description_max_length'],
    ];

    $form['menu']['link']['menu_parent'] = $parent_element;
    $form['menu']['link']['menu_parent']['#title'] = t('Parent link');
    $form['menu']['link']['menu_parent']['#attributes']['class'][] = 'menu-parent-select';

    $form['menu']['link']['weight'] = [
      '#type' => 'number',
      '#title' => t('Weight'),
      '#default_value' => $defaults['weight'],
      '#description' => t('Menu links with lower weights are displayed before links with higher weights.'),
    ];

    // INSERING EXTRAS WRAPPER AND FORM
    //could use a test here for extras
    self::_create_extra_wrapper($form, $form_state, $form_id);
    if ($link = self::_get_saved_menulinkcontent_instance($form_state)) {

      foreach ($link->getFieldDefinitions() as $field_name => $field_def) {
        if ($field_def instanceof BaseFieldDefinition) {
          $form_state->setValue($field_name, $link->get($field_name)->value);
          $form_state->set(['menu', $field_name], $link->get($field_name)->value);
        }
      }
      //This seems redundant since we did this at the defaults stage.
      $is_extra = ExtraUtils::checkBundleHasExtraFieldsThanEntity('menu_link_content', $link->getMenuName());
      if ($is_extra) {
        foreach ($link->getFieldDefinitions() as $field_name => $field_def) {
          if (!($field_def instanceof BaseFieldDefinition)) {
            if (str_starts_with($field_name, 'field_')) {
              $form_state->setValue($field_name, $link->get($field_name)->value);
              $form_state->set(['menu', 'link', 'extra', $field_name], $link->get($field_name)->value);
            }
          }
        }
      }

      ///BUILDFORM
      $form_display = EntityFormDisplay::load('menu_link_content.' . $link->getMenuName() . '.nodeform');
      if ($form_display && ($form_display instanceof EntityFormDisplay || $form_display instanceof EntityFormDisplayInterface)) {
        $form_display->buildForm($link, $form['menu']['link']['extra'], $form_state);

        foreach (Element::children($form['menu']['link']['extra']) as $key) {
          //only showing extras, custom fields and view mode
          if ($key != 'tempstore_key' && strpos($key, 'field_') !== 0 && strpos($key, 'view_mode') !== 0) {
            unset($form['menu']['link']['extra'][$key]);
          } // this special casing might belong to the nodeform view mode in future
          //breaking elseif here to make new set
          if ($key == 'field_path_prefix') {
            $form['menu']['link']['extra'][$key]['#states'] =
              ['invisible' => [':input[name="menu[extra][field_menu_landing][value]"]' => ['checked' => FALSE]]];
          }
        }
        //HOPING THIS MEANS I DON'T NEED TO REPRO SUBMIT AND SAVE
        foreach (array_keys($form['actions']) as $action) {
          if ($action != 'preview' && isset($form['actions'][$action]['#type']) && $form['actions'][$action]['#type'] === 'submit') {
            $form['actions'][$action]['#submit'][] = [$this, '_save_menu_link_fields'];
          }
        }
        //dvm($form['actions']);
      }
      else {
        //TDOD Exception.
      }


      //do tempstore on initial so we keep loaded vals if parent is changed within same menu.
      //might move this up top in yse_casades_...alter
      if (\Drupal::request()->isXmlHttpRequest() !== TRUE) {
        $nodeformuuid = self::_get_tempstore_key($form, $form_state);
        $initbundle = $link->toArray()['bundle'][0]['target_id'] ?? NULL;
        self::_save_temp_state($form, $form_state, $initbundle, $link->toArray());
      }
      else {
        //ajax
      }
    }
    else {
      //allow menu_ui to take over?
    }
    // END ExTRAS

    //foreach (array_keys($form['actions']) as $action) {
    //  if ($action != 'preview' && isset($form['actions'][$action]['#type']) && $form['actions'][$action]['#type'] === 'submit') {
    //    $form['actions'][$action]['#submit'][] = 'menu_ui_form_node_form_submit';
    //  }
    //}

    //allowing menu_ui to take it
    //$form['#entity_builders'][] = [$this, 'yse_cascades_node_builder'];
  }


  /**
   * Entity form builder to add the menu information to the node.
   * depreciated
   */
  function yse_cascades_node_builder($entity_type, NodeInterface $entity, &$form, FormStateInterface $form_state, $form_id = NULL) {
    $entity->menu = $form_state->getValue('menu');
  }


  //depreciated
  private function _unnest_link_content($arr) {
    if (!empty($arr['extra'])) {
      $ex_extra = array_map(fn($key) => $key['value'] ?? $key[0]['value'], $arr['extra']);
      unset($arr['extra']);
      $outboundarr = array_merge($arr, $ex_extra);
    }
    else {
      $filteredarr = array_filter($arr, "self::_filter_link_content", ARRAY_FILTER_USE_KEY);
      $outboundarr = array_map(fn($key) => $key['value'] ?? $key[0]['value'] ?? NULL, $filteredarr);
      //allowing nodeform rather than menu_link_content to shape the array
      $outboundarr['entity_id'] = $outboundarr['id'] ?? 0;
      $outboundarr['id'] = $outboundarr['uuid'] ? 'menu_link_content:' . $outboundarr['uuid'] : NULL;
      $outboundarr['menu_parent'] = $outboundarr['parent'] ? $outboundarr['menu_name'] . ':' . $outboundarr['parent'] : NULL;
      $outboundarr['bundle'] = $outboundarr['menu_name'] ?? NULL;
    }
    return $outboundarr;
  }
  //depreciated using the basefields vs extras now
  private function _filter_link_content($key) {
    $keepkeys = ['extra', 'id', 'entity_id', 'uuid', 'bundle', 'enabled', 'title', 'description', 'menu_name', 'external', 'parent', 'menu_parent', 'tempstore_key', 'view_mode'];
    return (in_array($key, $keepkeys) || strpos($key, 'field_') === 0);
  }

  /**
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function _save_menu_link_fields(array $form, FormStateInterface $form_state, $form_id = NULL) {
    //check if link enabled toggle was set to 'off'
    $menu_above = $form_state->getValue('menu');
    if (empty($menu_above['enabled'])) {
      return;
    }

    // Entity builders run twice, once during validation and again during
    // submission, so we only run this code after validation has been performed.
    if (!$form_state->isValueEmpty('menu') && $form_state->getTemporaryValue('entity_validated')) {

      // Don't create a menu link if the node is not being saved.
      $triggering_element = $form_state->getTriggeringElement();
      if (!$triggering_element || !isset($triggering_element['#submit']) || !in_array('::save', $triggering_element['#submit'])) {
        return;
      }

      if ($link = self::_get_saved_menulinkcontent_instance($form_state)) {
        if ($link && $link->getUrlObject()->getRouteName()) {
          $form_display = EntityFormDisplay::load('menu_link_content.' . $link->getMenuName() . '.nodeform');
          //Do not process if parent_menu is not handled by menu_item_extras
          //Do we need more of a test here?
          //WAIT - WHY DO I NEED TO GO BACK AND GET INSTANCE IF THE DATA IS HERE?  DOES EXTRACT DO THAT?
          //SHOULD I JUST SAVE EXTRAS AND WAIT FOR MENU_UI TO SAVE OR DELETE FIRST?
          //DO THE not base fields here?
          if ($form_display && ($form_display instanceof EntityFormDisplay || $form_display instanceof EntityFormDisplayInterface)) {
            $form_display->extractFormValues($link, $form['menu']['link']['extra'], $form_state);
            $link->save();
            self::_delete_temp_state($form, $form_state, $form_id);
          }
        }
      }
    }
  }






  function _create_extra_wrapper(array &$form, FormStateInterface &$form_state, $form_id) {
    //add extras container for ajax support
    if (isset($form['menu']['link']['menu_parent']) && empty($form['menu']['link']['extra'])) {
      $element =& $form['menu']['link'];
      $element['extra'] = [
        '#attributes' => ['id' => 'edit-menu-extra'],
        '#type' => 'container',
        '#parents' => ['menu', 'extra'],
        '#tree' => '1',
      ];

      if (empty($form['menu']['link']['tempstore_key'])) {
        $element['tempstore_key'] = array(
          '#attributes' => ['id' => 'tempstore_key'],
          '#type' => 'hidden',
          '#value' => self::_make_tempstore_key($form, $form_state, $form_id),
        );
      }
    }
    //define ajax support trigger and target
    if (isset($form['menu']['link']['menu_parent']) && empty($form['menu']['link']['menu_parent']['#ajax'])) {
      $element =& $form['menu']['link']['menu_parent'];
      $element['#ajax'] = [
        'callback' => [$this, 'edit_menu_extra_refresh'],
        'event' => 'change',
        'wrapper' => 'edit-menu-extra',
        'method' => 'replace',
      ];
    }
    // editing referenced form so no return necessary
  }

  function _get_empty_extra_wrapper(array &$form, FormStateInterface &$form_state, $form_id = NULL) {
    if (empty($form['menu']['link']['menu_parent']['#ajax'])) {
      //returning null is information for caller
      return;
    }
    if (empty($form['menu']['link']['extra'])) {
      //returning null is information for caller
      return;
    }
    //if we have the container, empty everything but tempstore key
    //shouldn't we just smash?
    if (!empty($form['menu']['link']['extra'])) {
      foreach (Element::children($form['menu']['link']['extra']) as $key) {
        if ($key != 'tempstore_key') {
          unset($form['menu']['link']['extra'][$key]);
        }
      }
    }
    //editing referenced form so no return necessary if form is sound
  }

  function _get_fauxprocessed_fieldwidget(array &$form, FormStateInterface &$form_state, $form_id, $key) {
    // We need to reproduce some of the processForm magic here.
    // Right now we are assuming null multiples, card and max_delta of 0 if any.
    $w = NULL;
    $k = str_replace('_', '-', $key);
    if (!empty($form['menu']['link']['extra'][$key]['widget']['value'])) {
      $w =& $form['menu']['link']['extra'][$key]['widget'];
      $c = 'edit-menu-extra-' . $k . '-value';
    }
    elseif (!empty($form['menu']['link']['extra'][$key]['widget']['0']['value'])) {
      $w =& $form['menu']['link']['extra'][$key]['widget']['0'];
      $c = 'edit-menu-extra-' . $k . '-0-value';
    }

    if (isset($w)) {
      $w['value']['#id'] = $c;
      $w['value']['#attributes']['id'] = $c;
      $w['value']['#attributes']['data-drupal-selector'] = $c;
      $w['value']['#attributes']['name'] = 'menu[extra][' . $key . '][value]';
      $w['value']['#description'] = $w['#description'];
      $w['value']['#description_display'] = 'after';

      if ($w['value']['#type'] == 'checkbox') {
        //if we uncouple the checkbox label from the field label, show it
        //if (isset($w['#title']) && isset($w['value']['#title']) && ($w['#title'] != $w['value']['#title'])) {
        //  $w['value']['#field_prefix'] = $w['#title'];
        //}
      }
      if (empty($w['#field_name'])) {
        $w['#field_name'] = $key;
      }
    }
    //TODO make this a config item?
    if ($key == 'field_path_prefix') {
      $form['menu']['link']['extra'][$key]['#states'] =
        ['invisible' => [':input[name="menu[extra][field_menu_landing][value]"]' => ['checked' => FALSE]]];
    }

    //TODO make a big assumption that if we reuse fields they have the same utility
    //watch for triggeringElement only?
    $swapin = TRUE;
    if (isset($swapin)) {
      //use the form_state that we built above, its flattened.
      switch ($w['value']['#type']) {
        case 'checkbox':
          $v = $form_state->getValue($key) ?? NULL;
          $w['value']['#checked'] = $v ?? FALSE;
          $w['value']['#default_value'] = $v ?? FALSE;
          $w['value']['#value'] = $v ?? FALSE;
          break;
        case "select":
          $v = $form_state->getValue($key) ?? NULL;
          $o = ($v && isset($w['value']['#options'][$v])) ? $v : NULL;
          $w['value']['#default_value'] = $o;
          $w['value']['#value'] = $o;
          break;
        case "textfield":
          $v = $form_state->getValue($key) ?? NULL;
          $w['value']['#default_value'] = $v;
          $w['value']['#value'] = $v;
          break;
      }
    }
    // editing referenced form so no return necessary
  }

  function _get_saved_menulinkcontent_instance(FormStateInterface $form_state) {
    $node = $form_state->getFormObject()->getEntity();
    $defaults = self::get_menu_link_defaults($node);
    //comes through with 0 might be considered isset.
    if (!empty($defaults['entity_id'])) {
      return MenuItemExtrasMenuLinkContent::load($defaults['entity_id']);
    }
    return MenuItemExtrasMenuLinkContent::create($defaults);
  }

  function _create_menulinkcontent_instance(FormStateInterface $form_state, $bundle = NULL) {

    $defaults = [];
    if (isset($bundle)) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('menu_link_content', $bundle);
      $max_length = $field_definitions['title']->getSetting('max_length');
      $description_max_length = $field_definitions['description']->getSetting('max_length');
      $defaults = [
        'entity_id' => 0,
        'id' => '',
        'title' => '',
        'title_max_length' => $max_length,
        'description' => '',
        'description_max_length' => $description_max_length,
        'menu_name' => $bundle,
        'parent' => '',
        'weight' => 0,
      ];

      foreach ($field_definitions as $field_name => $field_def) {
        if (!($field_def instanceof BaseFieldDefinition)) {
          if (str_starts_with($field_name, 'field_')) {
            $defaults[$field_name] = '';
          }
        }
      }
    }
    else {
      $node = $form_state->getFormObject()->getEntity();
      $defaults = self::get_menu_link_defaults($node);
    }
    return MenuItemExtrasMenuLinkContent::create($defaults);
  }

  //tempstore management
  //not perfect, unsaved might clash if same person has two of the same form open...
  function _get_prior_state(array $form, FormStateInterface $form_state, $menuformuuid = NULL) {
    $tempstore = $this->privateTempStoreFactory->get('yse_menu_item_extras');
    $nodeformuuid = self::_get_tempstore_key($form, $form_state);
    $tempobj = $tempstore->get('previous_extra_menudata_' . $nodeformuuid);
    return isset($menuformuuid) ? $tempobj[$menuformuuid] : $tempobj;
  }

  //assuming here we save one subform snap at a time via $menuformuuid, not a larger retrieval/rewrite
  function _save_temp_state(array $form, FormStateInterface $form_state, $bundle, $object = NULL) {
    //TODO: create real exception for missing $menuformuuid
    //inelegant way to break having the same token for every node add in a session.
    $tempstore = $this->privateTempStoreFactory->get('yse_menu_item_extras');
    $nodeformuuid = self::_get_tempstore_key($form, $form_state);
    $tempobj = $tempstore->get('previous_extra_menudata_' . $nodeformuuid) ?? [];
    $temparr = $object ?? $form_state->getValues()['menu'] ?? [];
    //flatten from form to simple array
    //NOPE
    //$temparr = self::_unnest_link_content($temparr);
    $tempobj['last_menu_chosen'] = $bundle ?? $temparr['bundle'] ?? NULL;
    $tempobj[$bundle] = $temparr;
    $tempstore->set('previous_extra_menudata_' . $nodeformuuid, $tempobj);
    return $tempobj;
  }

  function _delete_temp_state(array $form, FormStateInterface $form_state, $form_id = NULL) {
    $tempstore = $this->privateTempStoreFactory->get('yse_menu_item_extras');
    $nodeformuuid = self::_get_tempstore_key($form, $form_state);
    $tempstore->delete('previous_extra_menudata_' . $nodeformuuid);
  }

  function _make_tempstore_key(array $form, FormStateInterface $form_state, $form_id = NULL) {
    //match for page_form or _edit_form in name, then choose entity id or timestamp.
    if (!empty($form_state->getUserInput()) && !empty($form_state->getUserInput()['menu'])) {
      $menudata = $form_state->getUserInput()['menu'];
      $tempkey = $menudata['tempstore_key'];
    }
    elseif (str_ends_with($form_id, 'edit_form')) {
      $tempkey = $form_id . '-' . $form_state->getformObject()->getEntity()->id();
    }
    else {
      //add form gets created stamp in case multiples are being made at the same time
      $tempkey = $form_id . '-' . $this->dateFormatter->format($form_state->getFormObject()->getEntity()->getCreatedTime(), 'custom', 'U');
    }
    return $tempkey;
  }

  function _get_tempstore_key(array $form, FormStateInterface $form_state, $form_id = NULL) {
    if (!empty($form_state->getUserInput()) && !empty($form_state->getUserInput()['menu'])) {
      $menudata = $form_state->getUserInput()['menu'];
      $tempkey = $menudata['tempstore_key'];
      return $tempkey;
    }
    elseif (!empty($form['menu']['link']['tempstore_key']['#value'])) {
      $tempkey = $form['menu']['link']['tempstore_key']['#value'];
      return $tempkey;
    }
    //TDOD Exception.
  }



  /**
   * Returns the definition for a menu link for the given node.
   *
   * @param \Drupal\node\NodeInterface $node
   * The node entity.
   *
   * @return array
   * An array that contains default values for the menu link form.
   */

  function get_menu_link_defaults(NodeInterface $node) {
    // Prepare the definition for the edit form.
    /** @var \Drupal\node\NodeTypeInterface $node_type */
    $node_type = $node->type->entity;
    $menu_name = strtok($node_type->getThirdPartySetting('menu_ui', 'parent', 'main:'), ':');
    $defaults = FALSE;
    if ($node->id()) {
      $id = FALSE;
      // Give priority to the default menu
      $type_menus = $node_type->getThirdPartySetting('menu_ui', 'available_menus', ['main']);
      // If there are no menus available to this node type, exit.
      // Would be cool to break this out and check menus for Extras here
      if (empty(array_values($type_menus))) {
        return;
      }

      // First check for extras rendernodes/homenodes
      $query = \Drupal::entityQuery('menu_link_content');

      $query->accessCheck(TRUE)
        ->condition('link.uri', 'entity:node/' . $node->id())
        ->condition('menu_name', array_values($type_menus), 'IN')
        ->condition('primary', TRUE)
        ->sort('id', 'ASC')
        ->range(0, 1);
      $result = $query->execute();

      $id = (!empty($result)) ? reset($result) : FALSE;

      //then check main first, to keep with original menu_ui logic
      if (!$id && in_array($menu_name, $type_menus)) {
        $query = \Drupal::entityQuery('menu_link_content')
          ->accessCheck(TRUE)
          ->condition('link.uri', 'entity:node/' . $node->id())
          ->condition('menu_name', $menu_name)
          ->sort('id', 'ASC')
          ->range(0, 1);
        $result = $query->execute();

        $id = (!empty($result)) ? reset($result) : FALSE;
      }
      // Check all allowed menus if a link does not exist in the default menu.
      if (!$id && !empty($type_menus)) {
        $query = \Drupal::entityQuery('menu_link_content')
          ->accessCheck(TRUE)
          ->condition('link.uri', 'entity:node/' . $node->id())
          ->condition('menu_name', array_values($type_menus), 'IN')
          ->sort('id', 'ASC')
          ->range(0, 1);
        $result = $query->execute();

        $id = (!empty($result)) ? reset($result) : FALSE;
      }

      //not sure if I should use MenuItemExtrasMenuLinkContent here and embellish.
      //going to try without, since we only have these on enabled menus

      if ($id) {
        $menu_link = MenuItemExtrasMenuLinkContent::load($id);
        /// was using MenuLinkContentBase,
        /// but MenuItemExtrasMenuLinkContent seems to handle vanilla objs well.

        ///removing extra lookups for a sec,
        /// $is_extra = ExtraUtils::checkBundleHasExtraFieldsThanEntity('menu_link_content', $menu_link->getMenuName());
        /// if ($is_extra) {
        ///  $menu_link = MenuItemExtrasMenuLinkContent::load($id);
        ///}


        /// BUT DO WE STILL NEED VIEWMODE HERE?
        /// SHOULD PASS THROUGH TO DEFAULT IF NO nodeform if setting is OK.
        /// this is for backend not nodeform display so nah...

        $menu_link = $this->entityRepository->getTranslationFromContext($menu_link);
        $menu_link_fielddefs = $menu_link->getFieldDefinitions();

        $defaults = [
          'entity_id' => $menu_link->id(),
          'id' => $menu_link->getPluginId(),
          'title' => $menu_link->getTitle(),
          'title_max_length' => $menu_link_fielddefs['title']->getSetting('max_length'),
          'description' => $menu_link->getDescription(),
          'description_max_length' => $menu_link_fielddefs['description']->getSetting('max_length'),
          'menu_name' => $menu_link->getMenuName(),
          'parent' => $menu_link->getParentId(),
          'weight' => $menu_link->getWeight(),
        ];

        /// if ($is_extra) {
        foreach ($menu_link_fielddefs as $field_name => $field_def) {
          if (!($field_def instanceof BaseFieldDefinition)) {
            if (str_starts_with($field_name, 'field_')) {
              $defaults[$field_name] = $menu_link->get($field_name)->value;
            }
          }
        }
        ///extras }
      }
    }

    if (!$defaults) {
      // Get the default max_length of a menu link title from the base field
      // definition.
      $blank_bundle = in_array('main', $type_menus) ? 'main' : array_shift($type_menus);
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('menu_link_content', $blank_bundle);
      $max_length = $field_definitions['title']->getSetting('max_length');
      $description_max_length = $field_definitions['description']->getSetting('max_length');
      $defaults = [
        'entity_id' => 0,
        'id' => '',
        'title' => '',
        'title_max_length' => $max_length,
        'description' => '',
        'description_max_length' => $description_max_length,
        'menu_name' => $menu_name,
        'parent' => '',
        'weight' => 0,
      ];

      foreach ($field_definitions as $field_name => $field_def) {
        if (!($field_def instanceof BaseFieldDefinition)) {
          if (str_starts_with($field_name, 'field_')) {
            $defaults[$field_name] = '';
          }
        }
      }
    }
    return $defaults;
  }



}
