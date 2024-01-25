# YSE Cascading Content

This module tracks hierarchy and helps pages, nodes, and menus to work together as a logical collection with secondary nav and cascading field potential.

This module relies on having one field configured using menu_item_extra, which serves to indicate when a menu item's node serves as a 'homenode', meaning everything below that and above another homenode has a hierarchical relationship to the homenode as a landing page or chapter, etc.

Nodes can also indicate that they recognize a homenode by virtue of a reference field on the node.  But this might be less desirable... Maybe we should use form_alter to indicate that there is a homenode in the menu.

