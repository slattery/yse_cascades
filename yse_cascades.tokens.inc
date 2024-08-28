<?php

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\node\Entity\Node as NodeLoader;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\menu_item_extras\Entity\MenuItemExtrasMenuLinkContent;
use Drupal\menu_item_extras\Utility\Utility as ExtraUtils;
use Drupal\menu_link_content\Entity\MenuLinkContent as MenuLinkContentBase;
use Drupal\system\Entity\Menu;

// /admin/config/search/path/settings
// CAREFUL NOW, look for tokens that have slashes and put them in AS A SAFE TOKEN

/**
 * Implements hook_token_info_alter().
 */
function yse_cascades_token_info_alter(&$info) {
  if (\Drupal::moduleHandler()->moduleExists('menu_item_extras')) {
    $mlc_token_type = \Drupal::service('token.entity_mapper')->getTokenTypeForEntityType('menu_link_content');

    $info['tokens']['menu-link']['content'] = [
      'name' => t('Menu Link Content'),
      'description' => t('The menu of the menu link.'),
      'type' => $mlc_token_type,
    ];



    foreach (array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('menu_link_content')) as $bundle) {
      foreach (\Drupal::service('entity_field.manager')->getFieldDefinitions('menu_link_content', $bundle) as $field_name => $field_def) {
        $token_name = str_replace("field_", "token_", $field_name);

        if (empty($info['types'][$token_name]) && !($field_def instanceof BaseFieldDefinition)) {

          $info['types'][$token_name] = array(
            'name' => $field_def->getLabel(),
            'description' => t('Direct and calculated values'),
            'needs-data' => $token_name,
            'nested' => TRUE,
          );

          foreach (['source', 'values', 'objects'] as $thestuff) {
            $info['tokens'][$token_name][$thestuff] = [
              'name' => $thestuff,
              'description' => $field_def->getLabel() . ' ' . $thestuff,
              'module' => 'yse_cascades',
              'type' => 'array',
            ];
          }

          $info['tokens'][$token_name]['landing'] = [
            'name' => 'landing',
            'description' => 'Tokens for arbitrary landing pages.',
            'module' => 'yse_cascades',
            'type' => 'landing',
          ];

          $info['tokens']['cascades'][$token_name] = array(
            'name' => 'Page ' . $field_def->getLabel(),
            'description' => t('Fetched and calcluated values on a page'),
            'type' => $token_name,
          );
        }
      }
    }
  }
}

/**
 * Implements hook_token_info().
 */
function yse_cascades_token_info() {
  //removed needs-data arg for now
  //when does this get run?
    $mlc_token_type = \Drupal::service('token.entity_mapper')->getTokenTypeForEntityType('menu_link_content');

  $types['cascades'] = array(
    'name' => t('Cascades'),
    'description' => t('Menu link content in hierarchy'),
  );

  $tokens['cascades']['stepparents'] = array(
  'name' => 'stepparents',
  'description' => t('Alternate pathauto builder'),
  'type' => 'array',
  );

  $tokens['cascades']['stepself'] = array(
  'name' => 'stepself',
  'description' => t('Short w/fallback to Menu'),
  );

  $tokens['current-page']['cascades'] = array(
    'name' => t('Page cascades'),
    'description' => t('Menu link content in hierarchy'),
    'type' => 'cascades',
  );

  $tokens['node']['cascades'] = array(
      'name' => t('Node cascades'),
      'description' => t('Menu link content in hierarchy'),
      'type' => 'cascades',
    );

  $types['landing'] = array(
    'name' => t('Landing'),
    'description' => t('Tokens for arbitrary landing pages.'),
  );

  $tokens['landing']['content'] = [
    'name' => 'Content',
    'description' => 'Landing menu content',
    'module' => 'yse_cascades',
    'type' => $mlc_token_type,
  ];

  $tokens['landing']['node'] = [
      'name' => 'Landing node content',
      'description' => 'The node',
      'type' => 'node'
  ];

  return array(
    'types' => $types,
    'tokens' => $tokens,
  );
}


/**
 * Implements hook_tokens().
 */
function yse_cascades_tokens($type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $bubbleable_metadata->addCacheContexts(['url.path', 'url.site']);
  $token_service = \Drupal::token();
  $replacements = [];
  $am_i_in_scope = TRUE;
  $cascades_token_types = _get_cascades_token_family();

  if ($type == 'current-page') {

    $node = \Drupal::routeMatch()->getParameter('node');

    if ($cascade_node_tokens = $token_service->findWithPrefix($tokens, 'cascades')) {

      $replacements += $token_service->generate(
        'cascades',
        $cascade_node_tokens,
        ['node' => $node],
        $options,
        $bubbleable_metadata
      );
    }

  } //current-page


  if ($type == 'node') {

    $node = $data['node'] ?? \Drupal::routeMatch()->getParameter('node');

    if(!empty($node)){
      if ($nodely_tokens = $token_service->findWithPrefix($tokens, 'cascades')) {
          $replacements += $token_service->generate(
            'cascades',
            $nodely_tokens,
            ['node' => $node],
            $options,
            $bubbleable_metadata
          );
        }
    }
  }//node


  if ($type == 'cascades') {

    $ind = array_keys($data);
    $akind = implode('|', $ind);

    $int = array_keys($tokens);
    $akint = implode('|', $int);




    $node = $data['node'] ?? \Drupal::routeMatch()->getParameter('node');

    if (!empty($node)) {
      $menu_link = \Drupal::service('yse_cascades.nodeform_utils')->get_menu_link_defaults($node);
    }

    if (empty($menu_link['entity_id'])) {
      return;
    }

    $active_link = MenuItemExtrasMenuLinkContent::load($menu_link['entity_id']);

    if (!empty($active_link) && ($active_link instanceof MenuLinkInterface || $active_link instanceof MenuItemExtrasMenuLinkContent)) {

      $active_menu = $active_link->getMenuName();
      $active_trail = \Drupal::service('menu.active_trail')->getActiveTrailIds($active_menu);

      //we like that activeTrail is caching, but it always returns an empty array member
      if (!empty($active_trail) && is_array($active_trail)) {
        unset($active_trail[NULL]);
      }

      //active trail always returns something, it looks like even if we strip the empty anchor pair with unset
      //when pathauto uses this, the active trail will never populate in context.
      //use 'menu.active_trail' and menu mgr for web render based replacements so we get the cache
      //use 'plugin.manager.menu.link' for pathauto because context and it is hopefully rare.


      if (isset($active_link)) {
        $link_bundle = $active_link->bundle();
        foreach (array_keys($cascades_token_types) as $token_name) {
          if ($values_tokens = \Drupal::token()->findWithPrefix($tokens, $token_name)) {
            $replacements += $token_service->generate(
              $token_name,
              $values_tokens,
              [$token_name => ['active_link' => $active_link, 'active_trail' => $active_trail]],
              $options,
              $bubbleable_metadata
            );
          }
        }

        //reset active_trail for pathauto replacements
        $active_trail = \Drupal::service('plugin.manager.menu.link')->getParentIds($active_link->getPluginId());



        foreach ($tokens as $name => $original) {
          switch ($name) {
            case 'stepself':
              $str = _swapout_stepself($active_link);
              $replacements[$original] = $str ?? NULL;
              break;
          }
        }
        // [cascades:stepparents:*] chained tokens.
        if ($parents_tokens = \Drupal::token()->findWithPrefix($tokens, 'stepparents')) {
          if ($parents = _assemble_stepparents_array($active_link, $active_trail)) {
            $replacements += \Drupal::token()->generate('array', $parents_tokens, ['array' => $parents], $options, $bubbleable_metadata);
          }
        }
      }
    }
    else {
      ////active_link is not a full fledged content holder
      return;
    }
  }//cascades





  //WHY ARE THESE PLURAL IT SEEMS LIKE ONLY ONE PAIR AT A TIME
  // "values" => "[current-page:cascades:token_feature_pathprefix:values]"


  if (!empty($cascades_token_types) && in_array($type, array_keys($cascades_token_types))) {
    if (!empty($data[$type]['active_link'])) {
      $active_link = $data[$type]['active_link'];
      $active_plugin = $active_link->getPluginId();
      $active_trail = $data[$type]['active_trail'];
    }
    else {
      return [];
    }

    if (empty($active_plugin) || empty($active_trail)) {
      return [];
    }

    $token_field_name = str_replace("token_", "field_", $type);
    $subs = \Drupal::service('yse_cascades.tree_utils')->harvest_menu_link_content_extras($active_plugin, $active_trail, $token_field_name);
    if (!empty($subs) && !empty($subs[$token_field_name])) {

      foreach ($tokens as $name => $original) {
        //find out which flavor is requested and process
        switch ($name) {
          case 'source':
            if (!empty($subs[$token_field_name]['source_nodes'])) {
              $src = $subs[$token_field_name]['source_nodes'];
              // sending array down to chain for first/join/etc.
              $replacements[$original] = $src ?? NULL;
            }
            break;
          case 'values':
            if (!empty($subs[$token_field_name]['value_string'])) {
              $str = $subs[$token_field_name]['value_string'];
              // sending array down to chain for first/join/etc.
              $replacements[$original] = $str ?? NULL;
            }
            break;
          case 'objects':
            if (!empty($subs[$token_field_name]['value_object'])) {
              $obj = $subs[$token_field_name]['value_object'];
             // sending array down to chain for first/join/etc.
              $replacements[$original] = $obj ?? NULL;
            }
            break;
          case 'landing':
            if (!empty($subs[$token_field_name]['value_string'])) {
              $nid = array_values($subs[$token_field_name]['value_string'])[0];
              $replacements[$original] = $nid ?? NULL;
            }
            break;
        }
      }
      //chaining array features (first, last, join-path, etc.)
      if (!empty($subs[$token_field_name]['source_nodes'])) {
        if ($parents_tokens = \Drupal::token()->findWithPrefix($tokens, 'source')) {
          $replacements += \Drupal::token()->generate('array', $parents_tokens, ['array' => $subs[$token_field_name]['source_nodes']], $options, $bubbleable_metadata);
        }
      }
      if (!empty($subs[$token_field_name]['value_string'])) {
        if ($parents_tokens = \Drupal::token()->findWithPrefix($tokens, 'values')) {
          $replacements += \Drupal::token()->generate('array', $parents_tokens, ['array' => $subs[$token_field_name]['value_string']], $options, $bubbleable_metadata);
        }
      }
      if (!empty($subs[$token_field_name]['value_object'])) {
        if ($parents_tokens = \Drupal::token()->findWithPrefix($tokens, 'objects')) {
          $replacements += \Drupal::token()->generate('array', $parents_tokens, ['array' => $subs[$token_field_name]['value_object']], $options, $bubbleable_metadata);
        }
        if ($parents_tokens = \Drupal::token()->findWithPrefix($tokens, 'landing')) {
          $landing_link = array_values($subs[$token_field_name]['value_object'])[0];
          $landing_nid  = array_values($subs[$token_field_name]['value_string'])[0];
          $replacements += \Drupal::token()->generate('landing', $parents_tokens, ['landing' => $landing_link, 'nid' => $landing_nid], $options, $bubbleable_metadata);
        }
      }
    }
    //return $replacements;

  }//fields

  if ($type == 'landing'){

        if (!empty($data['landing']) && $contentloader_tokens = $token_service->findWithPrefix($tokens, 'content')) {
            $replacements += $token_service->generate(
              'menu_link_content',
              $contentloader_tokens,
              ['menu_link_content' => $data['landing']],
              $options,
              $bubbleable_metadata);
          }

          if (!empty($data['nid']) && $nodeloader_tokens = $token_service->findWithPrefix($tokens, 'node')) {
            $landing_node = NodeLoader::load($data['nid']);
            $replacements += $token_service->generate(
              'node',
              $nodeloader_tokens,
              ['node' => $landing_node],
              $options,
              $bubbleable_metadata);
          }
  }//landing

//TODO
//with 'content' and 'node' taking place in cascades, we might not need this or we should do only nonBase fields.
//to do that we need to capture this and maybe not hand over to regular entity mapping.
//this may require a new type for menu_link_content rather than just a token which means this might need
//yet another token to hang on like ['extras']['cpntent'] where 'extras' is the type and 'content' always stands for nonBase fields.
  if ($type == 'menu-link' && !empty($data['menu-link']) && \Drupal::moduleHandler()->moduleExists('menu_item_extras')) {
    $link = $data['menu-link'];
    if ($link instanceof MenuLinkContentInterface or $link instanceof Drupal\menu_link_content\Plugin\Menu\MenuLinkContent) {
      $menu_link = \Drupal::service('plugin.manager.menu.link')->createInstance($link->getPluginId());
      $menu_link_entity = \Drupal::service('menu_item_extras.menu_link_tree_handler')->getMenuLinkItemEntity($menu_link);
      if (!empty($menu_link_entity)) {
        if ($menuref_tokens = $token_service->findWithPrefix($tokens, 'content')) {
          $replacements += $token_service->generate(
            'menu_link_content',
            $menuref_tokens,
            ['menu_link_content' => $menu_link_entity],
            $options,
            $bubbleable_metadata
          );
        }
      }
    }
  }//menu-link

  return $replacements;

}//tokens func

function _get_cascades_token_family() {
  static $tokfam;
  if (empty($tokfam)) {
    $tokfam = [];
    foreach (\Drupal::service('token.tree_builder')->buildTree('cascades', ['depth' => 2]) as $branch_token => $fruit) {
      if (!empty($fruit['type'])){
        $tokfam[$fruit['type']] = [];
        if (!empty($fruit['children'])){
          foreach ($fruit['children'] as $shoot) {
            if (!empty($shoot) && isset($shoot['token'])){
              $e = explode(':', $shoot['token'] );
              $n = isset($e) ? end($e) : NULL;
              if(!empty($n)){
                $tokfam[$fruit['type']][$n] = 1;
              }
            }
          }
        }
      }
    }
  }
  return $tokfam;
}

function _swapout_stepself($active_link){
    $step_shorty = (!empty($active_link->get('field_path_shortcode')->getString())) ? $active_link->get('field_path_shortcode')->getString() : $active_link->getTitle();
    $step_out = (!empty($step_shorty)) ? \Drupal::service('pathauto.alias_cleaner')->cleanstring($step_shorty) : NULL;
    return $step_out;
}

function _assemble_stepparents_array($active_link, $active_trail = NULL, $fieldname = NULL) {

  if (empty($active_trail)) {
    return NULL;
  }
  $stepparents = [];
  $landing_plugin = $landing_link = NULL;

  $active_plugin = $active_link->getPluginId();
  //$active_entity = \Drupal::service('menu_item_extras.menu_link_tree_handler')->getMenuLinkItemEntity($active_link);

  //Someday we can support separate landing streams, for now we expect one per menu bundle
  //We don't need to know the name just the field clue of 'field_landing_*'
  //if we find a landing, this will truncate the step climb and could add a prefix to the path.

  if (!empty($fieldname)) {
    $landing_result = \Drupal::service('yse_cascades.tree_utils')->climb_and_gather($active_trail, 'landing', $fieldname);
  }
  else {
    foreach ($active_link->getFieldDefinitions() as $field_name => $field_def) {
      if (str_starts_with($field_name, 'field_landing')) {
        $landing_result = \Drupal::service('yse_cascades.tree_utils')->climb_and_gather($active_trail, 'landing', $field_name);
        break;
      }
    }
  }

  if($landing_result && !empty($landing_result['value_object'])){
    $landing_plugin = array_key_first($landing_result['value_object']);
    $landing_link   = $landing_result['value_object'][$landing_plugin];
  }


  //steps should have a string for each level in the menu to the root
  //we want to remove the string for the node where the token is called, the title is more often used
  //we want to stop progressing up the steps if we encounter a landing node
  //if we encounter a landing, we need to get the prefix and the shortcode from that node link content
  $steps_result = \Drupal::service('yse_cascades.tree_utils')->climb_and_gather($active_trail, 'swapout', 'field_path_shortcode');
  if (!empty($steps_result) && !empty($steps_result['value_string'])){

    $steps = $steps_result['value_string'];
  }
  else {
    return [];
  }

  //not unshifted yet, we need to test against landing first
  $stepkeys = array_keys($steps);

  if (!empty($landing_link)) {
    //$landing_entity = \Drupal::service('menu_item_extras.menu_link_tree_handler')->getMenuLinkItemEntity($landing_link);
    $step_prefix = $landing_link->get('field_path_prefix')->getString() ?? NULL;
    $step_shorty = $landing_link->get('field_path_shortcode')->getString() ?? $landing_entity->getTitle();
    $landing_pair = [];
    if (!empty($step_prefix)) {
      $steps['stepparents_prefix'] = $step_prefix;
    }

    //is the current node a landing node?
    //if so, the prefix will be enough (assuming stepself is set on this menu)
    if ($landing_plugin == $active_plugin) {
      return ['stepparents_prefix' => $step_prefix];
    }
  }
  array_shift($stepkeys);

  $tempkeys = [];
  foreach ($stepkeys as $k) {
    if ($landing_plugin && $landing_plugin == $k) {
      //attach pair and break;
      array_unshift($tempkeys, $k);
      array_unshift($tempkeys, 'stepparents_prefix');
      break;
    }
    else {
      array_unshift($tempkeys, $k);
    }
  }

  //unshift puts keys in the order we want.
  foreach ($tempkeys as $k) {
    $stepparents[$k] = $steps[$k];
  }

  return $stepparents;

}
