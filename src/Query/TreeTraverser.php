<?php
/**
 * PromptWire Tree Traverser
 * 
 * Provides methods for traversing the ProcessWire page tree structure,
 * including hierarchical tree views, ancestor (breadcrumb) trails,
 * and sibling navigation.
 * 
 * @package     PromptWire
 * @subpackage  Query
 * @author      Peter Knight <https://www.peterknight.digital>
 * @license     MIT
 */

namespace PromptWire\Query;

/**
 * Traverses page tree structure
 * 
 * This class provides methods to explore the page hierarchy:
 * - getTree: Build a hierarchical tree starting from any page
 * - getAncestors: Get the parent chain (breadcrumbs) for a page
 * - getSiblings: Get pages at the same level as a given page
 * 
 * These methods are useful for understanding site structure and
 * navigation without fetching full page content.
 * 
 * Note: This class is not currently exposed via CLI commands but
 * is available for Phase 2 or custom extensions.
 */
class TreeTraverser {
    
    /**
     * ProcessWire instance
     * 
     * @var \ProcessWire\ProcessWire
     */
    private $wire;
    
    /**
     * PageQuery instance for formatting
     * 
     * @var PageQuery
     */
    private $pageQuery;
    
    /**
     * Create a new TreeTraverser
     * 
     * @param \ProcessWire\ProcessWire $wire ProcessWire instance
     */
    public function __construct($wire) {
        $this->wire = $wire;
        $this->pageQuery = new PageQuery($wire);
    }
    
    /**
     * Get page tree starting from a root page
     * 
     * Builds a hierarchical tree structure starting from the specified
     * root page, descending to the specified maximum depth.
     * 
     * Each node contains basic page info plus a 'children' array
     * (if the page has children and we haven't reached maxDepth).
     * 
     * Example output:
     * {
     *   "id": 1,
     *   "name": "home",
     *   "path": "/",
     *   "title": "Home",
     *   "template": "home",
     *   "numChildren": 5,
     *   "children": [...]
     * }
     * 
     * @param int|string $rootIdOrPath Root page ID or path (default: "/")
     * @param int        $maxDepth     Maximum depth to traverse (default: 3)
     * @return array Tree structure or error array
     */
    public function getTree($rootIdOrPath = '/', int $maxDepth = 3): array {
        // Get the root page
        if (is_numeric($rootIdOrPath)) {
            $root = $this->wire->pages->get((int) $rootIdOrPath);
        } else {
            $root = $this->wire->pages->get($rootIdOrPath);
        }
        
        if (!$root || !$root->id) {
            return ['error' => "Root page not found: $rootIdOrPath"];
        }
        
        return $this->buildTree($root, 0, $maxDepth);
    }
    
    /**
     * Recursively build tree structure
     * 
     * Creates a node for the current page and recursively adds
     * children nodes up to the maximum depth.
     * 
     * @param \ProcessWire\Page $page         Current page
     * @param int               $currentDepth Current depth in tree
     * @param int               $maxDepth     Maximum depth to traverse
     * @return array Tree node with optional children
     */
    private function buildTree($page, int $currentDepth, int $maxDepth): array {
        // Build node for current page
        $node = [
            'id' => $page->id,
            'name' => $page->name,
            'path' => $page->path,
            'title' => $page->title,
            'template' => $page->template->name,
            'numChildren' => $page->numChildren,
        ];
        
        // Add children if we haven't reached max depth and page has children
        if ($currentDepth < $maxDepth && $page->numChildren > 0) {
            $children = [];
            // include=all to find hidden/unpublished pages
            foreach ($page->children('include=all') as $child) {
                $children[] = $this->buildTree($child, $currentDepth + 1, $maxDepth);
            }
            $node['children'] = $children;
        }
        
        return $node;
    }
    
    /**
     * Get ancestors of a page (breadcrumb trail)
     * 
     * Returns the parent chain from the root to the specified page,
     * useful for building breadcrumb navigation.
     * 
     * Example output:
     * {
     *   "page": {"id": 123, "path": "/about/team/", "title": "Team"},
     *   "ancestors": [
     *     {"id": 1, "name": "home", "path": "/", "title": "Home"},
     *     {"id": 45, "name": "about", "path": "/about/", "title": "About"}
     *   ]
     * }
     * 
     * @param int|string $idOrPath Page ID or path
     * @return array Page with ancestors array, or error array
     */
    public function getAncestors($idOrPath): array {
        // Get the page
        if (is_numeric($idOrPath)) {
            $page = $this->wire->pages->get((int) $idOrPath);
        } else {
            $page = $this->wire->pages->get($idOrPath);
        }
        
        if (!$page || !$page->id) {
            return ['error' => "Page not found: $idOrPath"];
        }
        
        // Build ancestors array (from root to parent)
        $ancestors = [];
        foreach ($page->parents() as $parent) {
            $ancestors[] = [
                'id' => $parent->id,
                'name' => $parent->name,
                'path' => $parent->path,
                'title' => $parent->title,
            ];
        }
        
        return [
            'page' => [
                'id' => $page->id,
                'path' => $page->path,
                'title' => $page->title,
            ],
            'ancestors' => $ancestors,
        ];
    }
    
    /**
     * Get siblings of a page
     * 
     * Returns all pages at the same level (same parent) as the
     * specified page, including the page itself (marked with isCurrent).
     * 
     * Useful for building sub-navigation menus.
     * 
     * Example output:
     * {
     *   "page": {"id": 123, "path": "/about/", "title": "About"},
     *   "siblings": [
     *     {"id": 120, "name": "services", "path": "/services/", "title": "Services", ...},
     *     {"id": 123, "name": "about", "path": "/about/", "title": "About", "isCurrent": true},
     *     {"id": 125, "name": "contact", "path": "/contact/", "title": "Contact", ...}
     *   ]
     * }
     * 
     * @param int|string $idOrPath Page ID or path
     * @return array Page with siblings array, or error array
     */
    public function getSiblings($idOrPath): array {
        // Get the page
        if (is_numeric($idOrPath)) {
            $page = $this->wire->pages->get((int) $idOrPath);
        } else {
            $page = $this->wire->pages->get($idOrPath);
        }
        
        if (!$page || !$page->id) {
            return ['error' => "Page not found: $idOrPath"];
        }
        
        // Get all siblings including the current page
        $siblings = [];
        foreach ($page->siblings('include=all') as $sibling) {
            $siblings[] = [
                'id' => $sibling->id,
                'name' => $sibling->name,
                'path' => $sibling->path,
                'title' => $sibling->title,
                'template' => $sibling->template->name,
                'isCurrent' => $sibling->id === $page->id,  // Mark the current page
            ];
        }
        
        return [
            'page' => [
                'id' => $page->id,
                'path' => $page->path,
                'title' => $page->title,
            ],
            'siblings' => $siblings,
        ];
    }
}
