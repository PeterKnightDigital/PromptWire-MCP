<?php
namespace PwMcp\Query;

/**
 * Handles page queries and formatting
 */
class PageQuery {
    
    private $wire;
    
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Get a page by ID or path
     */
    public function getPage($idOrPath, bool $includeFiles = false): ?array {
        if (is_numeric($idOrPath)) {
            $page = $this->wire->pages->get((int) $idOrPath);
        } else {
            $page = $this->wire->pages->get($idOrPath);
        }
        
        if (!$page || !$page->id) {
            return null;
        }
        
        return $this->formatPage($page, $includeFiles, true);
    }
    
    /**
     * Query pages by selector
     */
    public function query(string $selector, int $limit = 100): array {
        // Add include=all if not specified
        if (strpos($selector, 'include=') === false) {
            $selector .= ', include=all';
        }
        
        // Add limit if not specified
        if (strpos($selector, 'limit=') === false) {
            $selector .= ", limit=$limit";
        }
        
        $pages = $this->wire->pages->find($selector);
        $results = [];
        
        foreach ($pages as $page) {
            $results[] = $this->formatPage($page, false, false);
        }
        
        return $results;
    }
    
    /**
     * Format a page for output
     */
    public function formatPage($page, bool $includeFiles = false, bool $includeFields = true): array {
        $data = [
            'id' => $page->id,
            'name' => $page->name,
            'path' => $page->path,
            'url' => $page->url,
            'template' => $page->template->name,
            'title' => $page->title,
            'status' => $page->status,
        ];
        
        if ($includeFields) {
            $data['created'] = date('c', $page->created);
            $data['modified'] = date('c', $page->modified);
            $data['createdUser'] = $page->createdUser ? $page->createdUser->name : null;
            $data['modifiedUser'] = $page->modifiedUser ? $page->modifiedUser->name : null;
            $data['fields'] = $this->formatFields($page, $includeFiles);
        }
        
        return $data;
    }
    
    /**
     * Format all fields of a page
     */
    private function formatFields($page, bool $includeFiles = false): array {
        $fields = [];
        
        foreach ($page->template->fields as $field) {
            $value = $page->get($field->name);
            $fields[$field->name] = $this->formatFieldValue($field, $value, $includeFiles);
        }
        
        return $fields;
    }
    
    /**
     * Format a single field value
     */
    private function formatFieldValue($field, $value, bool $includeFiles = false) {
        // Handle null/empty
        if ($value === null || $value === '') {
            return null;
        }
        
        // Handle page references
        if ($value instanceof \ProcessWire\Page) {
            return $this->formatPageReference($value);
        }
        
        // Handle page arrays
        if ($value instanceof \ProcessWire\PageArray) {
            $pages = [];
            foreach ($value as $p) {
                $pages[] = $this->formatPageReference($p);
            }
            return $pages;
        }
        
        // Handle images/files
        if ($value instanceof \ProcessWire\Pagefiles || $value instanceof \ProcessWire\Pageimages) {
            return $this->formatFiles($value, $includeFiles);
        }
        
        // Handle repeaters
        if ($value instanceof \ProcessWire\RepeaterPageArray) {
            return $this->formatRepeater($value, $includeFiles);
        }
        
        // Handle WireArray (generic)
        if ($value instanceof \ProcessWire\WireArray) {
            return $value->getArray();
        }
        
        // Handle DateTime
        if ($value instanceof \DateTime) {
            return $value->format('c');
        }
        
        // Default: return as-is
        return $value;
    }
    
    /**
     * Format a page reference
     */
    private function formatPageReference($page): array {
        if (!$page || !$page->id) {
            return null;
        }
        
        return [
            'id' => $page->id,
            'title' => $page->title,
            'path' => $page->path,
        ];
    }
    
    /**
     * Format files/images
     */
    private function formatFiles($files, bool $includeFiles = false): array {
        if (!$includeFiles) {
            return ['_count' => $files->count()];
        }
        
        $result = [];
        foreach ($files as $file) {
            $fileData = [
                'filename' => $file->name,
                'url' => $file->url,
                'size' => $file->filesize,
                'description' => $file->description ?: null,
            ];
            
            // Add image-specific data
            if ($file instanceof \ProcessWire\Pageimage) {
                $fileData['width'] = $file->width;
                $fileData['height'] = $file->height;
            }
            
            $result[] = $fileData;
        }
        
        return $result;
    }
    
    /**
     * Format repeater items
     */
    private function formatRepeater($repeater, bool $includeFiles = false): array {
        $items = [];
        
        foreach ($repeater as $item) {
            $itemFields = [];
            foreach ($item->template->fields as $field) {
                // Skip internal repeater fields
                if (strpos($field->name, 'repeater_') === 0) {
                    continue;
                }
                $itemFields[$field->name] = $this->formatFieldValue($field, $item->get($field->name), $includeFiles);
            }
            $items[] = $itemFields;
        }
        
        return $items;
    }
}
