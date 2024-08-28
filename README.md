# YSE Cascading Content

This module tracks hierarchy and helps pages, nodes, and menus to work together as a logical collection with secondary nav and cascading field potential.

## Dependencies

This module relies on having menu fields configured using menu_item_extra, which serves to indicate when a menu item's node serves as a 'landing', meaning everything below that and above another landing has a hierarchical relationship to the landing page as a sub or chapter, etc.

This module uses menu_entity_index to help keep track when multiple links point to the same node.

## Safe Token Setting

Array type tokens use the pathauto service to make strings safe, but if there will be feature or collect tokens that you want to use in a path, you must go to
/admin/config/search/path/settings and declare them as safe tokens, or add them to the pathauto.settings.yml under 'safe_tokens'

## Nodeform Support

This module has a Service which alters the node edit and add forms.  Please check for current hardcoded type/bundle status.   Menu Link Content Extra fields and viewmode should be accessible for Extras enabled menus from the node forms.

Please use the 'nodeform' form mode (found in install dir) for your menus until we support this in config.

## Token Behaviors

There are currently four behaviors for admin created fields, and naming conventions for fields menus using menu item extras will signal their function in a menu hierarchy.
- onestop
- catalog
- collect
- feature
- landing

There are also two special fields to go with 'landing', field_path_prefix and field_path_shortcode.   There is a special base field called 'primary' which signals that if there are more than one menu item pointing to a given node, the primary is the one that belongs in the nodeform, and is the basis for pathbuilding.

There are three token segments that will return values
 - values:  an array of strings, skips empties
 - objects: an array of structures or entities, skips entities
 - landing: a single nid
 - swapout: an array of strings accepts defaults to avoid skips

### onestop: boolean field (values)

Fields that have names like field_onestop_* will look for the first positive value and return the node id tha them menu item holds (if a link is external or unrouted, no value will return.) 'values' will return a one-element array, so use 'first' to get the nid.  ex: [cascades:field_onestop_nicenode:values:first]

### catalog: boolean field
Fields with positive values will have their associated nids added to the array. calling for 'catalog' by itself should give you an comma delimited array.   Calling the 'values' token allows for array features like 'first' and 'join'
ex: [cascades:field_catalog_nicenodes] //1,2,3
ex: [cascades:field_catalog_nicenodes:values:join:-] // 1-2-3

### collect: text field

Fields with positive values will be added to the array.  'values' will return strings, 'objects' will give you the returm of getValue() on the field, which may contain markup, etc.
ex: [cascades:field_collect_nicenodes] // one,two,four
ex: [cascades:field_collect_nicenodes:values:first] //one

### feature: text field
Tokens look only at the current menu item for a value
ex: [cascades:field_feature_basketball:values:first] //spalding

### landing: boolean field

Like onestop, the first positive value in the hierarchy climb will be the array eleeent returned.  'landing' alone returns the nid associated with the orimary menu item.  There are subsegments for 'content' which gives access to the menu item content from the landing node's primary menu item, and 'node' which returns values from the associated node itself.
ex: [cascades:field_landing_mezzanine] \\ 6436 (nid)
ex: [cascades:field_landing_mezzanine:values:first] \\ 6436
ex: [cascades:token_landing_mezzanine:landing:content:title] \\ Menu Link Title
ex: [cascades:token_landing_mezzanine:landing:node:title] \\ Node Title

### Pathbuilding

There are two tokens that call on two fixed name fields to build paths: 'field_path_prefix' and 'field_path_shortcode'

 - stepparents: an array of path-friendly strings from the menu hierarchy that come from 'field_path_shortcode' with the menu item title for fallback
 - stepself: a string with the 'current' 'field_path_shortcode' or menu item title

If any adjacent field_landing_* field is positive in the climb, the path will start from that node and include the field_path_prefix string.

ex: [cascades:stepparents:join-path] // one/two/three
ex: [cascades:stepself] // four
With fully populated shortcodes
ex: [cascades:stepparents:join-path]/[cascades:stepself] // one/two/three/four
ex: [cascades:stepparents:join-path]/[node:title] // one/two/three/four-is-the-title
With some menu items falling back
ex: [cascades:stepparents:join-path]/[cascades:stepself] //one/there-are-two/three/four


## Nodeform additions

We add per-menu item fields that are not base fields or view mode, into the node form where you specify the menu link parent and position on the nodeform

We also add extra fields under the normal fields for menu item reports per node.
