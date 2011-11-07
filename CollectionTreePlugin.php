<?php
require_once 'Omeka/Plugin/Abstract.php';
class CollectionTreePlugin extends Omeka_Plugin_Abstract
{
    protected $_hooks = array(
        'install', 
        'uninstall', 
        'after_save_form_collection', 
        'collection_browse_sql', 
        'admin_append_to_collections_form', 
        'admin_append_to_collections_show_primary', 
        'public_append_to_collections_show', 
        'admin_append_to_items_form_collection', 
    );
    
    protected $_filters = array(
        'admin_navigation_main', 
        'public_navigation_main', 
    );
    
    /**
     * Install the plugin.
     * 
     * One collection can have AT MOST ONE parent collection. One collection can 
     * have ZERO OR MORE child collections.
     * 
     * A limited release version (v0.1, named "Nested") of this plugin may still 
     * be used by a handful of early adopters. Because of the plugin name 
     * change, they must delete the Nested plugin directory without uninstalling 
     * the plugin, save this plugin in the plugins directory, and install this 
     * plugin as normal.
     */
    public function installHook()
    {
        // collection_id must be unique to satisfy the AT MOST ONE parent 
        // collection constraint.
        $sql  = "
        CREATE TABLE IF NOT EXISTS {$this->_db->CollectionTree} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            parent_collection_id int(10) unsigned NOT NULL,
            collection_id int(10) unsigned NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY collection_id (collection_id)
        ) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
        $this->_db->exec($sql);
        
        
        // Determine if the old Nested plugin is installed.
        $nested = $this->_db->getTable('Plugin')->findByDirectoryName('Nested');
        if ($nested && $nested->version == '0.1') {
            
            // Delete Nested from the plugins table.
            $nested->delete();
            
            // Populate the new table.
            $sql = "
            INSERT INTO {$db->CollectionTree} (
                parent_collection_id
                collection_id, 
            ) 
            SELECT parent, child
            FROM {$db->prefix}nests";
            $this->_db->exec($sql);
            
            // Delete the old table.
            $sql = "DROP TABLE {$db->prefix}nests";
            $this->_db->query($sql);
        }
    }
    
    /**
     * Uninstall the plugin.
     */
    public function uninstallHook()
    {
        $sql = "DROP TABLE IF EXISTS {$this->_db->CollectionTree}";
        $this->_db->query($sql);
    }
    
    /**
     * Save the parent/child relationship.
     */
    public function afterSaveFormCollectionHook($collection, $post)
    {
        $collectionTree = $this->_db->getTable('CollectionTree')->findByCollectionId($collection->id);
        
        // Insert/update the parent/child relationship.
        if ($post['collection_tree_parent_collection_id']) {
            
            // If the collection is not already a child collection, create it.
            if (!$collectionTree) {
                $collectionTree = new CollectionTree;
                $collectionTree->collection_id = $collection->id;
            }
            $collectionTree->parent_collection_id = $post['collection_tree_parent_collection_id'];
            $collectionTree->save();
        
        // Delete the parent/child relationship if no parent collection is 
        // specified.
        } else {
            if ($collectionTree) {
                $collectionTree->delete();
            }
        }
    }
    
    /**
     * Omit all child collections from the collection browse.
     */
    public function collectionBrowseSqlHook($select, $params)
    {
        if (!is_admin_theme()) {
            $sql = "
            c.id NOT IN (
                SELECT nc.collection_id 
                FROM {$this->_db->CollectionTree} nc
            )";
            $select->where($sql);
        }
    }
    
    /**
     * Display the parent collection form.
     */
    public function adminAppendToCollectionsFormHook($collection)
    {
        $assignableCollections = $this->_db->getTable('CollectionTree')
                                           ->fetchAssignableParentCollections($collection->id);
        $options = array(0 => 'No parent collection');
        foreach ($assignableCollections as $assignableCollection) {
            $options[$assignableCollection['id']] = $assignableCollection['name'];
        }
        $collectionTree = $this->_db->getTable('CollectionTree')
                                    ->findByCollectionId($collection->id);
?>
<h2>Parent Collection</h2>
<div class="field">
    <?php echo __v()->formLabel('collection_tree_parent_collection_id','Select a Parent Collection'); ?>
    <div class="inputs">
        <?php echo __v()->formSelect('collection_tree_parent_collection_id', 
                                     $collectionTree->parent_collection_id, 
                                     null, 
                                     $options); ?>
    </div>
</div>
<?php
    }
    
    /**
     * Display the collection's parent collection and child collections.
     */
    public function adminAppendToCollectionsShowPrimaryHook($collection)
    {
        $this->_appendToCollectionsShow($collection);
    }
    
    /**
     * Display the collection's parent collection and child collections.
     */
    public function publicAppendToCollectionsShowHook()
    {
        $this->_appendToCollectionsShow(get_current_collection());
    }
    
    protected function _appendToCollectionsShow($collection)
    {
        $collectionTree = $this->_db->getTable('CollectionTree')->getCollectionTree($collection->id);
?>
<h2>Collection Tree</h2>
<?php echo self::getCollectionTreeList($collectionTree); ?>
<?php
    }
    
    /**
     * Display the full collection tree.
     */
    public function adminAppendToItemsFormCollectionHook($item)
    {
?>
<h2>Collection Tree</h2>
<?php echo self::getFullCollectionTreeList(false); ?>
<?php
    }
    
    /**
     * Add the collection tree page to the admin navigation.
     */
    public function adminNavigationMainFilter($nav)
    {
        $nav['Collection Tree'] = uri('collection-tree');
        return $nav;
    }
    
    /**
     * Add the collection tree page to the public navigation.
     */
    public function publicNavigationMainFilter($nav)
    {
        $nav['Collection Tree'] = uri('collection-tree');
        return $nav;
    }
    
    /**
     * Build a nested HTML unordered list of the full collection tree, starting 
     * at root collections.
     * 
     * @param bool $linkToCollectionShow
     * @return string
     */
    public static function getFullCollectionTreeList($linkToCollectionShow = true)
    {
        $html = '<ul style="list-style-type:disc;margin-bottom:0;list-style-position:inside;">';
        $rootCollections = get_db()->getTable('CollectionTree')->fetchRootCollections();
        foreach ($rootCollections as $rootCollection) {
            $html .= '<li>';
            if ($linkToCollectionShow) {
                $html .= self::linkToCollectionShow($rootCollection['id']);
            } else {
                $html .= $rootCollection['name'];
            }
            $collectionTree = get_db()->getTable('CollectionTree')->getDescendantTree($rootCollection['id']);
            $html .= self::getCollectionTreeList($collectionTree, $linkToCollectionShow);
            $html .= '</li>';
        }
        $html .= '</ul>';
        
        return $html;
    }
    
    /**
     * Recursively build a nested HTML unordered list from the provided 
     * collection tree.
     * 
     * @see CollectionTreeTable::getCollectionTree()
     * @see CollectionTreeTable::getAncestorTree()
     * @see CollectionTreeTable::getDescendantTree()
     * @param array $collectionTree
     * @param bool $linkToCollectionShow
     * @return string
     */
    public static function getCollectionTreeList($collectionTree, $linkToCollectionShow = true) {
        if (!$collectionTree) {
            return;
        }
        $html = '<ul style="list-style-type:disc;margin-bottom:0;list-style-position:inside;">';
        foreach ($collectionTree as $collection) {
            $html .= '<li>';
            if ($linkToCollectionShow && !isset($collection['current'])) {
                $html .= self::linkToCollectionShow($collection['id']);
            } else {
                $html .= $collection['name'];
            }
            $html .= self::getCollectionTreeList($collection['children'], $linkToCollectionShow);
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
    
    /**
     * Get the HTML link to the specified collection show page.
     * 
     * @see link_to_collection()
     * @param int $collectionId
     * @return string
     */
    public static function linkToCollectionShow($collectionId)
    {
        // Require the helpers libraries. This is necessary when calling this 
        // method before the libraries are loaded.
        require_once HELPERS;
        return link_to_collection(null, array(), 'show', 
                                  get_db()->getTable('Collection')->find($collectionId));
    }
}