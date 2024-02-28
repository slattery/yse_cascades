<?php

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\node\Entity\Node as NodeLoader;

// /admin/config/search/path/settings
// NEED TO DECLARE step_parent_path AS A SAFE TOKEN


/**
 * Implements hook_token_info_alter().
 */
function yse_cascades_token_info_alter(&$info) {
  if (\Drupal::moduleHandler()->moduleExists('menu_item_extras')) {
    $entity_type = 'menu_link_content';
    $token_type = \Drupal::service('token.entity_mapper')->getTokenTypeForEntityType($entity_type);

    $info['tokens']['menu-link']['custom'] = [
      'name' => t('Custom Menu Content'),
      'description' => t('The menu of the menu link.'),
      'type' => $token_type,
    ];
  }
}

/**
 * Implements hook_token_info().
 */
function yse_cascades_token_info() {
  //removed needs-data arg for now

  $types['cascades'] = array(
    'name' => t('cascades'),
    'description' => t('Tokens for arbitrary landings in a hierarchy.'),
  );

  $tokens['cascades']['menuname_txt'] = array(
    'name' => t('Menu'),
    'description' => t('Menu machine name for cascade'),
  );

  $tokens['cascades']['menuroot_txt'] = array(
    'name' => t('Menu root title'),
    'description' => t('Title string from root menu item.'),
  );

  $tokens['cascades']['homenode_txt'] = array(
    'name' => t('Menu title'),
    'description' => t('Title string from menu item.'),
  );

  $tokens['cascades']['homenode_pid'] = array(
    'name' => t('Menu slot'),
    'description' => t('Menu item id string for menu block.'),
  );

  $tokens['cascades']['homenode_self'] = array(
    'name' => t('Homenode self-id'),
    'description' => t('True if self is a homenode'),
  );

  $tokens['cascades']['homenode_nid'] = array(
    'name' => t('Homenode nid'),
    'description' => t('Homenode nid'),
  );

  $tokens['cascades']['superhero_yes'] = array(
    'name' => t('Use fixed background'),
    'description' => t('Flag for fixed hero bg'),
  );

  $tokens['cascades']['superhero_mid'] = array(
    'name' => t('Hero Media ID'),
    'description' => t('Media mid for homenode hero image'),
  );

  $tokens['cascades']['superhero_var'] = array(
    'name' => t('Hero CSS Var'),
    'description' => t('Overlay Settings for hero'),
  );

  $tokens['cascades']['superhero_txt'] = array(
    'name' => t('Hero Title Text'),
    'description' => t('Text for homenode supertitle'),
  );

  $tokens['cascades']['step_parent_path'] = array(
    'name' => t('Step Path'),
    'description' => t('Path to Homenode'),
  );

  $tokens['current-page']['cascades'] = array(
    'name' => t('Page cascades'),
    'description' => t('Casacading Info for Headers etc.'),
    'type' => 'cascades',
  );


  $types['homenode'] = array(
    'name' => t('homenode'),
    'description' => t('Tokens for entity associated with arbitrary landings in a hierarchy.'),
  );

  $tokens['homenode']['target_id'] = array(
    'name' => t('Homenode entity nid'),
    'description' => t('Pretend we have a noderef field'),
  );

  $tokens['homenode']['entity'] = array(
    'name' => t('Homenode entity'),
    'description' => t('Pretend we have a noderef field'),
    'type' => 'node'
  );

  $tokens['cascades']['homenode'] = array(
    'name' => t('Page Homenode'),
    'description' => t('Arbitrary Landing Node'),
    'type' => 'homenode',
  );

  return array(
    'types' => $types,
    'tokens' => $tokens,
  );
}


/**
 * Implements hook_tokens().
 */
function yse_cascades_tokens($type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $bubbleable_metadata->addCacheContexts(['url.path']);
  $token_service = \Drupal::token();
  $replacements = [];

  if ($type == 'current-page') {
    $node = \Drupal::routeMatch()->getParameter('node');

    if ($node && ($node instanceof NodeInterface or $node instanceof Drupal\node\Entity\Node) && in_array($node->bundle(), ['yse_sbunit_page'])) {
      // should be config based
      // do all the switch/lookup here

      if ($cascade_node_tokens = $token_service->findWithPrefix($tokens, 'cascades')) {
        $replacements += $token_service->generate(
          'cascades',
          $cascade_node_tokens,
          ['node' => $node],
          $options,
          $bubbleable_metadata
        );
      }
    }
  }

  if ($type == 'homenode') {

    $supernode = $data['homenode'];
    if ($supernode && ($supernode instanceof NodeInterface or $supernode instanceof Drupal\node\Entity\Node) && in_array($supernode->bundle(), ['yse_sbunit_page'])) {

      $datadelivery = ['entity_type' => 'node', 'token_type' => 'node', 'entity' => $supernode];
      // should be config based
      // do all the switch/lookup here

      //Perform replacements
      foreach ($tokens as $name => $original) {
        switch ($name) {
          case 'target_id':
            if ($supernid = $supernode->id()) {
              $replacements[$original] = $supernid;
            }
            break;
        }
      }

      if (($noderef_tokens = $token_service->findWithPrefix($tokens, 'entity')) && $super_repo = \Drupal::service('entity.repository')->getTranslationFromContext($supernode, LanguageInterface::LANGCODE_DEFAULT)) {
        $replacements += $token_service->generate(
          'node',
          $noderef_tokens,
          ['node' => $super_repo],
          $options,
          $bubbleable_metadata
        );
      }
    }
  }




  if ($type == 'cascades') {
    //menuname_txt, menuroot_txt, step_parents,
    //homenode_txt, homenode_pid, homenode_nid, homenode_self,
    //superhero_mid, superhero_var, superhero_txt, superhero_yes

    if ($data['node']) { //was current-page

      $node = $data['node']; //was current-page

      list(
        $menu_name, $menu_root_title, $menu_step_path,
        $menu_stop_title, $menu_stop_param, $menu_stop_nid, $menu_stop_self, $menu_stop_entity,
        $node_heromid, $node_herovar, $node_titlebg, $node_fixedbg)
        = _yse_cascades_escalate($node);

      //Set up cache deps
      $supernode = $localnode = $supermenu = $superstub = $superpath = NULL;

      if (!empty($node->id())) {
        /** @var \Drupal\node\NodeInterface $localnode */
        //maybe this is automatic?
        //$localnode = NodeLoader::load($node->id());
      }
      if (!empty($menu_stop_nid)) {
        /** @var \Drupal\node\NodeInterface $supernode */
        $supernode = NodeLoader::load($menu_stop_nid);
      }

      if (!empty($menu_stop_entity)) {
        /** @var \Drupal\menu_link_content\MenuLinkContentInterface $supermenu */
        $supermenu = $menu_stop_entity;
        if ($supermenu->hasField('field_path_prefix')) {
          $superstub = $supermenu->get('field_path_prefix')->value;
          $superpath = \Drupal::service('pathauto.alias_cleaner')->cleanString($menu_stop_title, $options);
          $menu_stop_pathpart = $superstub ?? $superpath;
        }
      }

      //Perform replacements
      foreach ($tokens as $name => $original) {
        switch ($name) {
          case 'menuname_txt':
            if ($menu_name && !empty($menu_name)) {
              $replacements[$original] = $menu_name;
            }
            break;
          case 'menuroot_txt':
            if ($menu_root_title && !empty($menu_root_title)) {
              $replacements[$original] = $menu_root_title;
            }
            break;
          case 'step_parent_path':
            if ($menu_step_path && !empty($menu_step_path)) {
              $replacements[$original] = $menu_step_path;
            }
            break;
          case 'homenode_txt':
            if ($menu_stop_title && !empty($menu_stop_title)) {
              $bubbleable_metadata->addCacheableDependency($supernode);
              $bubbleable_metadata->addCacheableDependency($supermenu);
              $replacements[$original] = $menu_stop_title;
            }
            break;
          case 'homenode_pid':
            if ($menu_stop_param && !empty($menu_stop_param)) {
              $bubbleable_metadata->addCacheableDependency($supernode);
              $bubbleable_metadata->addCacheableDependency($supermenu);
              $replacements[$original] = $menu_stop_param;
            }
            break;
          case 'homenode_nid':
            if ($menu_stop_nid && !empty($menu_stop_nid)) {
              $bubbleable_metadata->addCacheableDependency($supernode);
              $bubbleable_metadata->addCacheableDependency($supermenu);
              $replacements[$original] = $menu_stop_nid;
            }
            break;
          case 'homenode_self':
            if ($supernode) {
              $bubbleable_metadata->addCacheableDependency($supernode);
              $bubbleable_metadata->addCacheableDependency($supermenu);
            }
            $replacements[$original] = $menu_stop_self ? TRUE : FALSE;
            break;
          case 'superhero_yes':
            if ($supernode) {
              $bubbleable_metadata->addCacheableDependency($supernode);
            }
            $replacements[$original] = $node_fixedbg ? TRUE : FALSE;
            break;
          case 'superhero_mid':
            if ($node_heromid && !empty($node_heromid)) {
              $bubbleable_metadata->addCacheableDependency($supernode);
              $replacements[$original] = $node_heromid;
            }
            break;
          case 'superhero_var':
            if ($node_herovar && !empty($node_herovar)) {
              $bubbleable_metadata->addCacheableDependency($supernode);
              $replacements[$original] = $node_herovar;
            }
            break;
          case 'superhero_txt':
            if ($node_titlebg && !empty($node_titlebg)) {
              $bubbleable_metadata->addCacheableDependency($supernode);
              $replacements[$original] = $node_titlebg;
            }
            break;

        }
      }

      if ($supernode && $homenode_tokens = $token_service->findWithPrefix($tokens, 'homenode')) {
        $replacements += $token_service->generate(
          'homenode',
          $homenode_tokens,
          ['homenode' => $supernode],
          $options,
          $bubbleable_metadata
        );
      }


    }
  }


  if ($type == 'menu-link' && !empty($data['menu-link']) && \Drupal::moduleHandler()->moduleExists('menu_item_extras')) {
    $link = $data['menu-link'];
    if ($link instanceof MenuLinkContentInterface or $link instanceof Drupal\menu_link_content\Plugin\Menu\MenuLinkContent) {
      $menu_link = \Drupal::service('plugin.manager.menu.link')->createInstance($link->getPluginId());
      $menu_link_entity = \Drupal::service('menu_item_extras.menu_link_tree_handler')->getMenuLinkItemEntity($menu_link);
      if (!empty($menu_link_entity)) {
        if ($menuref_tokens = $token_service->findWithPrefix($tokens, 'custom')) {
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
  }

  return $replacements;
}


// add elses for current-page and paragraph assuming empty nodes.
// look to add two deps the menu-link entity and the node entity where needed?




function _yse_cascades_escalate($entity) {
  $menu_name = $menu_step_path = $menu_root_title = NULL;
  $menu_stop = $menu_stop_title = $menu_stop_param = $menu_stop_nid = $menu_stop_self = $menu_stop_entity = NULL;
  $node_heromid = $node_herovar = $node_titlebg = NULL;
  $node_fixedbg = FALSE;

  if ($entity && ($entity instanceof NodeInterface or $entity instanceof Drupal\node\Entity\Node) && (in_array($entity->bundle(), ['yse_sbunit_page'])) && \Drupal::moduleHandler()->moduleExists('menu_item_extras')) {
    $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
    $entity_id = $entity->id();
    $menu_links = $entity_id ? $menu_link_manager->loadLinksByRoute('entity.node.canonical', array('node' => $entity_id)) : [];
    if (is_array($menu_links) && count($menu_links)) {
      //some nodes may have more than one menu link, for now we only grabbing the first one.
      $menu_link = reset($menu_links);
      $menu_name = $menu_link->getMenuName();
      $menusteps = $menu_link_manager->getParentIds($menu_link->getPluginId());

      foreach ($menusteps as $step) {
        $menu_link_content = $menu_link_manager->createInstance($step);
        $menu_link_entity = \Drupal::service('menu_item_extras.menu_link_tree_handler')->getMenuLinkItemEntity($menu_link_content);
        if (!empty($menu_link_entity)) {
          if ($menu_link_entity->hasField('field_menu_landing')) {
            $menu_stop = $menu_link_entity->get('field_menu_landing')->value;
            if (!empty($menu_stop)) {
              $menu_stop_title = $menu_link_content->getTitle();
              $menu_stop_param = $menu_link_content->getPluginId();
              $menu_stop_nid = $menu_link_content->getRouteParameters()['node'];
              $menu_stop_self = ($menu_stop_nid == $entity_id) ? TRUE : FALSE;
              $menu_stop_entity = $menu_link_entity;
            }
          }
        }
        if ($step === array_key_last($menusteps)) {
          $menu_root_title = $menu_link_content->getTitle();
        }
      }
      $menu_step_path = _yse_climb_steppath($menusteps, $menu_stop_param);
    }

    // GRAB HERO STUFF FOR PAGE LEVEL
    if ($entity->hasField('field_hero_component')) {
      $superhero = NULL;
      $fixedbg = FALSE;

      //GET VIEWMODE FOR PAGE LEVEL - MIGHT WANT SETTINGS FOR DEFAULTS FOR STEPDOWNS
      $localhero = $entity->get('field_hero_component')->first();
      $localmode = $localhero->entity->getBehaviorSetting('paragraphs_viewmode_behavior', 'view_mode');
      if (!empty($localmode) && (str_contains($localmode, 'superhero'))) {
        $node_fixedbg = TRUE;
      }

      //GET HERO OBJS AND GRAB ASSETS
      if (!empty($menu_stop_nid) && $menu_stop_self == FALSE) {
        $super = \Drupal::entityTypeManager()->getStorage('node')->load($menu_stop_nid);
        $superhero = $super->get('field_hero_component')->first();
      }
      $our_hero = $superhero ?? $localhero;
      if (!empty($our_hero)) {
        if ($our_hero->entity->field_hero_image->entity) {
          $image_id = $our_hero->entity->field_hero_image->entity->thumbnail->target_id;
        }
        $node_heromid = $image_id ?? NULL;
        $node_titlebg = $our_hero->entity->field_hero_title->value ?? NULL;
        //seems safer to get all para behavior settings and do tests ourselves.
        $para_behaviors = $our_hero->entity->getAllBehaviorSettings();

        if (\Drupal::moduleHandler()->moduleExists('style_options')) {
          $behavior_config = \Drupal::service('style_options.discovery')->getDefinitions()['options'];
        }
        // get hero viewmode
        $hero_viewmode = !empty($para_behaviors['paragraphs_viewmode_behavior'])
          ? $para_behaviors['paragraphs_viewmode_behavior']['view_mode'] : NULL;
        $node_herovars = [];
        if (!empty($para_behaviors) && !empty($behavior_config)){
          // TODO get hero classes
          foreach (array_keys($para_behaviors['style_options']) as $b){
            if(!empty($para_behaviors['style_options'][$b]['css_var'])){
              $v = _yse_process_behavior($b, $para_behaviors, $behavior_config);
              if (!empty($v)){
                $node_herovars[] = $v;
              }
            }
          }
        }
        $node_herovar = !empty($node_herovars) ? implode(' ', $node_herovars) : NULL;
      }
    }
  }

  return [$menu_name, $menu_root_title, $menu_step_path,
    $menu_stop_title, $menu_stop_param, $menu_stop_nid, $menu_stop_self, $menu_stop_entity,
    $node_heromid, $node_herovar, $node_titlebg, $node_fixedbg];
}

function _yse_process_behavior($behavior, $para_behaviors, $behavior_config){
  //take into account plugin, index lookups and formatting
  //did empty check above
  $input = $para_behaviors['style_options'][$behavior]['css_var'];
  //our conventions call selects 'options' and numbers/text have 'params'
  $raw  = isset($behavior_config[$behavior]['options']) ? $behavior_config[$behavior]['options'][$input]['value'] : $input;
  $value = isset($behavior_config[$behavior]['format'])  ? sprintf($behavior_config[$behavior]['format'], $raw) : $raw;
  //check plugin for output, could evolve into a switch
  //our conventions put a 'css_var' key with the under/dash tr done, css_classes are just output as string
  $output = $behavior_config[$behavior]['plugin'] == 'yse_css_variable' ? "--{$behavior_config[$behavior]['css_var']}:{$value};" : $value;
  return $output;
}

function _yse_climb_steppath($menusteps, $menu_stop_param = NULL, $options = []) {
  $step_parents = [];
  $step_parent_path = NULL;

  if ($menusteps) {
    $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
    $langcode = NULL;  //someday!
    foreach ($menusteps as $step_pid) {
      $stepparent = $stepnid = $title_menuitem = $title_shortcode = $title_stephero = NULL;
      $path_seg = $path_seg_scrubbed = NULL;
      $stepsign = FALSE;

      $stepparent = $menu_link_manager->createInstance($step_pid);
      $title_menuitem = $stepparent->getTitle();
      // TEST IN CASE MENU ITEM IS MANUAL OR EXTERNAL
      $stepsign = $stepparent->getUrlObject()->isRouted();
      if ($stepsign) {
        $stepnid = $stepparent->getRouteParameters()['node'];
        $stepnode = \Drupal::entityTypeManager()->getStorage('node')->load($stepnid);
        $title_stepnode = $stepnode->getTitle();
      }

      if ($stepnode && $stepnode->hasField('field_hero_component')) {
        $step_hero = $stepnode->get('field_hero_component')->first();
        if ($step_hero && $hero_title = $step_hero->entity->field_hero_title->value) {
          $title_stephero = $hero_title;
        }
      }


      if ($stepparent) {
        $stepextras = \Drupal::service('menu_item_extras.menu_link_tree_handler')->getMenuLinkItemEntity($stepparent);
        if ($stepextras && $stepextras->hasField('field_path_shortcode') && $menu_shortcode = $stepextras->get('field_path_shortcode')->value) {
          $title_shortcode = $menu_shortcode;
        }
      }

      //TODO: CONFIG ORDER HERE
      $path_seg = $title_shortcode ?? $title_stephero ?? $title_menuitem ?? $title_stepnode;
      if (!empty($path_seg)) {
        $path_seg_scrubbed = \Drupal::service('pathauto.alias_cleaner')->cleanString($path_seg, $options);
      }

      if (!empty($path_seg_scrubbed)) {
        array_unshift($step_parents, $path_seg_scrubbed);
      }

      if ($menu_stop_param && array_key_exists($menu_stop_param, $menusteps) && ($menu_stop_param == $step_pid)) {
        break;
      }

    }
  }
  if (!empty($step_parents)) {
    $step_parent_path = implode('/', $step_parents);
  }
  return $step_parent_path;
}
