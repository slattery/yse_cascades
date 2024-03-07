<?php

namespace Drupal\yse_cascades\Service;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Form\FormState;
use Drupal\menu_item_extras\Entity\MenuItemExtrasMenuLinkContent;
use Drupal\menu_item_extras\Utility\Utility as ExtraUtils;

//use Drupal\Core\Access\CsrfTokenGenerator;

class YseNodeFormUtils {

  public function edit_menu_extra_refresh(array &$form, FormStateInterface &$form_state) {
    //we should know if we even need to swap.  same bundle means no-op

    if (!empty($form_state->getUserInput()) && !empty($form_state->getUserInput()['form_id'])) {
      $form_id = $form_state->getUserInput()['form_id'];
    }
    else {
      //TDOD Exception.
    }

    $menudata = $form_state->getUserInput()['menu'] ?? $form_state->getValues()['menu'] ?? [];
    //dvm(['uptop getVal metadata', $menudata]);
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

      $is_extra = ExtraUtils::checkBundleHasExtraFieldsThanEntity($entity_type, $bundle);
      if ($is_extra) {
        // we might be coming back from a menu with no extras so check and wrap if needed
        static::_get_empty_extra_wrapper($form, $form_state, $form_id);

        $stored_mlc = $stored_bun = $chosen_mlc = NULL;
        if (isset($menudata['entity_id']) && $menudata['entity_id'] > 0) {
          $stored_mlc = MenuItemExtrasMenuLinkContent::load($menudata['entity_id']);
          $stored_bun = $stored_mlc->getMenuName();
        }
        $chosen_mlc = static::_create_menulinkcontent_instance($form_state, $bundle);

        //TODO ship configs for nodeform view mode
        //Right now nodeform viewmode is identical to default but we name this for hook sake
        $merge_display = EntityFormDisplay::load('menu_link_content.' . $bundle . '.nodeform');
        if ($merge_display && $merge_display instanceof EntityFormDisplay) {
          //do no-op check.  if bundle did not change do not swap subform
          $priorstate = static::_get_prior_state($form, $form_state, $form_id);
          $ts = static::_save_temp_state($form, $form_state, $form_id, $bundle);
          if (!empty($priorstate) && isset($priorstate['last_menu_chosen'])) {
            if ($priorstate['last_menu_chosen'] == $bundle) {
              //no-op. I wish there was a 204 nicer way to bail out.
              exit;
            }
          }
          //TODO check tempstore and populate vals from choices if possible.
          //to accomplish this, we need to get from old mlc and get IF choices are valid...
          $merge_display->buildForm($chosen_mlc, $form['menu']['link']['extra'], $form_state);
          $merge_components = $merge_display->getComponents();
          foreach (Element::children($form['menu']['link']['extra']) as $key) {
            if ($key != 'tempstore_key' && (strpos($key, 'field_') !== 0 && strpos($key, 'view_mode') !== 0) || empty($merge_components[$key])) {
              unset($form['menu']['link']['extra'][$key]);
            }
            else {
              static::_get_fauxprocessed_fieldwidget($form, $form_state, $form_id, $key);
            }
          }
          return $form['menu']['link']['extra'];
        }
      }
      else {
        //hmmm this becomes node bundle somewhere...
        static::_save_temp_state($form, $form_state, $form_id, $bundle);
      }
    }
    // if we haven't returned a healthy subform, create and/or empty wrapper
    static::_get_empty_extra_wrapper($form, $form_state, $form_id);
    return $form['menu']['link']['extra'];
  }
  public function yse_sbunit_page_form_alter(&$form, FormStateInterface $form_state, $form_id) {
    //TODO, generalize to other page types.  Load storage configs on install but let bundles choose.
    // maybe base it on available menus and if they are extra enabled.
    module_set_weight('yse_cascades', '100');
    $activity = str_ends_with($form_id, 'edit_form') ? 'EDIT' : 'ADD';

    //Discovering behavior for alter invocations. Ajax alone is not enough check for isXmlHttpRequest
    //there is an alter pass with no information, we only act with we see a triggeringElement.
    if (\Drupal::request()->isXmlHttpRequest() !== TRUE) {
      $marker = 'no_ajax';
    }
    elseif (!empty($form_state->getTriggeringElement())) {
      $marker = 'ajax_w_trigger';
    }
    else {
      $marker = 'ajax_pre_trigger';
    }
    static::_create_extra_wrapper($form, $form_state, $form_id);
    if ($link = static::_get_saved_menulinkcontent_instance($form_state)) {
      $form_display = EntityFormDisplay::load('menu_link_content.' . $link->getMenuName() . '.nodeform');
      if ($form_display && $form_display instanceof EntityFormDisplay) {
        $form_display->buildForm($link, $form['menu']['link']['extra'], $form_state);

        foreach (Element::children($form['menu']['link']['extra']) as $key) {
          //only showing extras, custom fields and view mode
          if ($key != 'tempstore_key' && strpos($key, 'field_') !== 0 && strpos($key, 'view_mode') !== 0) {
            unset($form['menu']['link']['extra'][$key]);
          } // this special casing might belong to the nodeform view mode in future
          elseif (isset($form['menu']['link']['extra'][$key]['widget']['value']) && $form['menu']['link']['extra'][$key]['widget']['value']['#type'] == 'checkbox') {
            //if we uncouple the checkbox label from the field label, show it
            $w = $form['menu']['link']['extra'][$key]['widget'];
            if (isset($w['#title']) && isset($w['value']['#title']) && ($w['#title'] != $w['value']['#title'])) {
              $form['menu']['link']['extra'][$key]['widget']['value']['#field_prefix'] = $w['#title'];
            }
          }
          //TODO make this a config item?
          elseif ($key == 'field_path_prefix') {
            $form['menu']['link']['extra'][$key]['#states'] =
              ['invisible' => ['input[name="menu[extra][field_menu_landing][value]"]' => ['checked' => FALSE]]];
          }
        }
        foreach (array_keys($form['actions']) as $action) {
          if ($action != 'preview' && isset($form['actions'][$action]['#type']) && $form['actions'][$action]['#type'] === 'submit') {
            $form['actions'][$action]['#submit'][] = [$this, '_save_menu_link_fields'];
          }
        }
      }
      else {
        //TDOD Exception.
      }
      //do tempstore on initial so we keep loaded vals if parent is changed within same menu.
      if (\Drupal::request()->isXmlHttpRequest() !== TRUE) {
        $nodeformuuid = static::_get_tempstore_key($form, $form_state, $form_id);
        $initbundle = $link->toArray()['bundle'][0]['target_id'] ?? NULL;
        static::_save_temp_state($form, $form_state, $form_id, $initbundle, $link->toArray());
      }
    }
  }

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
  private function _filter_link_content($key) {
    $keepkeys = ['extra', 'id', 'entity_id', 'uuid', 'bundle', 'enabled', 'title', 'description', 'menu_name', 'external', 'parent', 'menu_parent', 'tempstore_key', 'view_mode'];
    return (in_array($key, $keepkeys) || strpos($key, 'field_') === 0);
  }

  /**
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function _save_menu_link_fields(array $form, FormStateInterface $form_state, $form_id = NULL) {
    if ($link = static::_get_saved_menulinkcontent_instance($form_state)) {
      if ($link && $link->getUrlObject()->getRouteName()) {
        $form_display = EntityFormDisplay::load('menu_link_content.' . $link->getMenuName() . '.nodeform');
        //Do not process if parent_menu is not handled by menu_item_extras
        if ($form_display && $form_display instanceof EntityFormDisplay) {
          $form_display->extractFormValues($link, $form['menu']['link']['extra'], $form_state);
          $link->save();
          static::_delete_temp_state($form, $form_state, $form_id);
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
          '#value' => static::_make_tempstore_key($form, $form_state, $form_id),
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

  function _get_empty_extra_wrapper(array &$form, FormStateInterface &$form_state, $form_id) {
    if (empty($form['menu']['link']['menu_parent']['#ajax'])) {
      //returning null is information for caller
      return;
    }
    if (empty($form['menu']['link']['extra'])) {
      //returning null is information for caller
      return;
    }
    //if we have the container, empty everything but tempstore key
    foreach (Element::children($form['menu']['link']['extra']) as $key) {
      if ($key != 'tempstore_key') {
        unset($form['menu']['link']['extra'][$key]);
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
        if (isset($w['#title']) && isset($w['value']['#title']) && ($w['#title'] != $w['value']['#title'])) {
          $w['value']['#field_prefix'] = $w['#title'];
        }
      }
      if (empty($w['#field_name'])) {
        $w['#field_name'] = $key;
      }
    }
    //TODO make this a config item?
    if ($key == 'field_path_prefix') {
      $form['menu']['link']['extra'][$key]['#states'] =
        ['invisible' => ['input[name="menu[extra][field_menu_landing][value]"]' => ['checked' => FALSE]]];
    }

    //TODO make a big assumption that if we reuse fields they have the same utility
    //watch for triggeringElement only
    $swapin = NULL;
    if (isset($swapin)) {
      $ps = static::_get_prior_state($form, $form_state, $form_id);
      $po = $form_state->getUserInput()['menu'] ?? $ps[$ps['last_menu_chosen']] ?? NULL;
      switch ($w['value']['#type']) {
        case 'checkbox':
          $v = $po['extra'][$key]['value'] ?? NULL;
          $w['value']['#default_value'] = $v ?? 0;
          $w['value']['#value'] = $v ?? 0;
          break;
        case "select":
          $v = $po['extra'][$key]['0']['value'] ?? NULL;
          $o = ($v && isset($w['value']['#options'][$v])) ? $v : NULL;
          $w['value']['#default_value'] = $o;
          $w['value']['#value'] = $o;
          break;
        case "textfield":
          $v = $po['extra'][$key]['0']['value'] ?? NULL;
          $w['value']['#default_value'] = $v;
          $w['value']['#value'] = $v;
          break;
      }
    }
    // editing referenced form so no return necessary
  }


  function _get_saved_menulinkcontent_instance(FormState $form_state) {
    $node = $form_state->getFormObject()->getEntity();
    $defaults = menu_ui_get_menu_link_defaults($node);
    if ($mlid = $defaults['entity_id']) {
      return MenuItemExtrasMenuLinkContent::load($mlid);
    }
    return MenuItemExtrasMenuLinkContent::create($defaults);
  }

  function _create_menulinkcontent_instance(FormState $form_state, $bundle = NULL) {
    $defaults = [];
    if (isset($bundle)) {
      $field_definitions = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('menu_link_content');
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
    }
    else {
      $node = $form_state->getFormObject()->getEntity();
      $defaults = menu_ui_get_menu_link_defaults($node);
    }
    return MenuItemExtrasMenuLinkContent::create($defaults);
  }

  //tempstore management
  //not perfect, unsaved might clash if same person has two of the same form open...
  function _get_prior_state(array $form, FormState $form_state, $form_id, $menuformuuid = NULL) {
    $tempstore = \Drupal::service('tempstore.private')->get('yse_menu_item_extras');
    $nodeformuuid = static::_get_tempstore_key($form, $form_state, $form_id);
    $tempobj = $tempstore->get('previous_extra_menudata_' . $nodeformuuid);
    return isset($menuformuuid) ? $tempobj[$menuformuuid] : $tempobj;
  }

  //assuming here we save one subform snap at a time via $menuformuuid, not a larger retrieval/rewrite
  function _save_temp_state(array $form, FormState $form_state, $form_id, $bundle, $object = NULL) {
    //TODO: create real exception for missing $menuformuuid
    //inelegant way to break having the same token for every node add in a session.
    $tempstore = \Drupal::service('tempstore.private')->get('yse_menu_item_extras');
    $nodeformuuid = static::_get_tempstore_key($form, $form_state, $form_id);
    $tempobj = $tempstore->get('previous_extra_menudata_' . $nodeformuuid) ?? [];
    $temparr = $object ?? $form_state->getValues()['menu'] ?? [];
    //flatten from form to simple array
    $temparr = static::_unnest_link_content($temparr);
    $tempobj['last_menu_chosen'] = $bundle ?? $temparr['bundle'] ?? NULL;
    $tempobj[$bundle] = $temparr;
    $tempstore->set('previous_extra_menudata_' . $nodeformuuid, $tempobj);
    return $tempobj;
  }

  function _delete_temp_state(array $form, FormState $form_state, $form_id) {
    $tempstore = \Drupal::service('tempstore.private')->get('yse_menu_item_extras');
    $nodeformuuid = static::_get_tempstore_key($form, $form_state, $form_id);
    $tempstore->delete('previous_extra_menudata_' . $nodeformuuid);
  }

  function _make_tempstore_key(array $form, FormState $form_state, $form_id) {
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
      $tempkey = $form_id . '-' . \Drupal::service('date.formatter')->format($form_state->getFormObject()->getEntity()->getCreatedTime(), 'custom', 'U');
    }
    return $tempkey;
  }

  function _get_tempstore_key(array $form, FormState $form_state, $form_id) {
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

}
