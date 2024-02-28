# YSE Cascading Content

This module tracks hierarchy and helps pages, nodes, and menus to work together as a logical collection with secondary nav and cascading field potential.

This module relies on having one field configured using menu_item_extra, which serves to indicate when a menu item's node serves as a 'homenode', meaning everything below that and above another homenode has a hierarchical relationship to the homenode as a landing page or chapter, etc.

Nodes can also indicate that they recognize a homenode by virtue of a reference field on the node.  But this might be less desirable... Maybe we should use form_alter to indicate that there is a homenode in the menu.

## Safe Token Setting

Please not that in order to use a new token in pathauto, you must go to
/admin/config/search/path/settings and declare them (ex: step_parent_path) as a safe token, or add them to the pathauto.settings.yml under 'safe_tokens'

## Nodeform Support

This module has a Service which alters the node edit and add forms.  Please check for current hardcoded type/bundle status.   Menu Link Content Extra fields and viewmode should be accessible for Extras enabled menus from the node forms.

Please use the 'nodeform' form mode (found in install dir) for your menus until we support this in config.
