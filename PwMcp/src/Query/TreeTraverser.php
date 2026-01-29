<?php
namespace PwMcp\Query;

/**
 * Traverses page tree structure
 */
class TreeTraverser {
    
    private $wire;
    private $pageQuery;
    
    public function __construct($wire) {
        $this->wire = $wire;
        $this->pageQuery = new PageQuery($wire);
    }
    
    /**
     * Get page tree starting from a root
     */
    public function getTree($rootIdOrPath = '/', int $maxDepth = 3): array {
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
     */
    private function buildTree($page, int $currentDepth, int $maxDepth): array {
        $node = [
            'id' => $page->id,
            'name' => $page->name,
            'path' => $page->path,
            'title' => $page->title,
            'template' => $page->template->name,
            'numChildren' => $page->numChildren,
        ];
        
        // Add children if we haven't reached max depth
        if ($currentDepth < $maxDepth && $page->numChildren > 0) {
            $children = [];
            foreach ($page->children('include=all') as $child) {
                $children[] = $this->buildTree($child, $currentDepth + 1, $maxDepth);
            }
            $node['children'] = $children;
        }
        
        return $node;
    }
    
    /**
     * Get ancestors of a page (breadcrumb trail)
     */
    public function getAncestors($idOrPath): array {
        if (is_numeric($idOrPath)) {
            $page = $this->wire->pages->get((int) $idOrPath);
        } else {
            $page = $this->wire->pages->get($idOrPath);
        }
        
        if (!$page || !$page->id) {
            return ['error' => "Page not found: $idOrPath"];
        }
        
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
     */
    public function getSiblings($idOrPath): array {
        if (is_numeric($idOrPath)) {
            $page = $this->wire->pages->get((int) $idOrPath);
        } else {
            $page = $this->wire->pages->get($idOrPath);
        }
        
        if (!$page || !$page->id) {
            return ['error' => "Page not found: $idOrPath"];
        }
        
        $siblings = [];
        foreach ($page->siblings('include=all') as $sibling) {
            $siblings[] = [
                'id' => $sibling->id,
                'name' => $sibling->name,
                'path' => $sibling->path,
                'title' => $sibling->title,
                'template' => $sibling->template->name,
                'isCurrent' => $sibling->id === $page->id,
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
